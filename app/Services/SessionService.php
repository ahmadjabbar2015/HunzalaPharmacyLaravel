<?php

namespace App\Services;

use App\Exceptions\SessionException;
use App\Models\DrawerSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * The shared cash drawer.
 *
 * A session belongs to the DRAWER, not to a person: whoever arrives first opens
 * it, whoever leaves last closes it, and each sale carries its own PIN
 * attribution. That is how the shop actually works, and modelling it as
 * per-user sessions would force staff to invent a fiction at the till.
 *
 * The variance this class computes is the shop's main control against till
 * shrinkage, which is why the arithmetic is small, explicit, and exact.
 *
 * Ported from shared/services/session_service.py. The close-blocking on pending
 * unsynced writes is deliberately absent: it exists to stop a drawer being
 * counted while sales are stranded in an outbox on another device, and
 * LARAVEL_PLAN.md §8 drops the sync layer for a web-only shop. With one server
 * there is no outbox and nothing to be stranded, so the block would never fire
 * and SessionCloseBlocked has no meaning here. If desktop tills ever return,
 * that guard comes back with the sync layer - not before.
 */
class SessionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PresenceService $presence,
    ) {}

    // -----------------------------------------------------------------------
    // queries
    // -----------------------------------------------------------------------

    /** The single open session, or null. There is at most one. */
    public function openSession(): ?DrawerSession
    {
        return DrawerSession::query()
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    /**
     * An open session left over from an earlier business day.
     *
     * The app prompts to close it on next launch. Without this, a drawer left
     * open overnight would quietly collect the next day's sales into yesterday's
     * takings, and the variance would be uninvestigable.
     */
    public function sessionNeedingClose(): ?DrawerSession
    {
        $open = $this->openSession();

        if ($open === null) {
            return null;
        }

        $openedOn = BusinessDate::for($open->opened_at);

        return $openedOn->lt(BusinessDate::for()) ? $open : null;
    }

    // -----------------------------------------------------------------------
    // open
    // -----------------------------------------------------------------------

    /**
     * Open the drawer for the evening.
     *
     * PIN confirmation happens in the UI before this is called; this trusts
     * $userUuid as the opener.
     *
     * @throws SessionException if a session is already open
     */
    public function open(
        string $userUuid,
        string|int|float|null $openingFloat,
        string $deviceId,
        bool $isProvisional = false,
        ?string $notes = null,
    ): DrawerSession {
        if ($this->openSession() !== null) {
            // Two open drawers would split one evening's takings across two
            // variance calculations, and neither would reconcile.
            throw new SessionException('a session is already open - join it instead of opening another');
        }

        return DB::transaction(function () use ($userUuid, $openingFloat, $deviceId, $isProvisional, $notes) {
            $openedAt = BusinessDate::nowUtc();

            $drawer = DrawerSession::create([
                // Dated by the OPENING business date, so a session running past
                // midnight stays one session rather than splitting in two.
                'session_date' => BusinessDate::for($openedAt)->toDateString(),
                'opened_by_user_uuid' => $userUuid,
                'opened_at' => $openedAt,
                // A null float means "not counted", which is different from a
                // counted float of zero. Both are legitimate; conflating them
                // would make an uncounted drawer look reconciled.
                'opening_float' => $openingFloat === null ? null : Money::format($openingFloat),
                'status' => 'open',
                'is_provisional' => $isProvisional,
                'notes' => $notes,
                'origin_device_id' => $deviceId,
            ]);

            $this->audit->record(
                action: config('pharmacy.audit.session_open'),
                deviceId: $deviceId,
                userUuid: $userUuid,
                sessionUuid: $drawer->uuid,
                details: [
                    'opening_float' => (string) $drawer->opening_float,
                    'is_provisional' => $isProvisional,
                ],
            );

            Log::info('session opened', [
                'session' => $drawer->uuid,
                'by' => $userUuid,
                'float' => $drawer->opening_float,
            ]);

            return $drawer;
        });
    }

    // -----------------------------------------------------------------------
    // cash arithmetic
    // -----------------------------------------------------------------------

    /**
     * SUM(net_amount) of CASH sales in this session.
     *
     * Mobile payments are excluded and reported separately: they never enter the
     * drawer, so counting them as expected cash would make every evening look
     * short by exactly the mobile takings.
     */
    public function cashSalesTotal(string $sessionUuid): string
    {
        return Money::format(
            Sale::query()
                ->where('session_uuid', $sessionUuid)
                ->where('payment_method', 'cash')
                ->sum('net_amount')
        );
    }

    /**
     * SUM(total_amount) of CASH refunds in this session - money handed back out
     * of the drawer.
     */
    public function cashRefundsTotal(string $sessionUuid): string
    {
        return Money::format(
            SaleReturn::query()
                ->where('session_uuid', $sessionUuid)
                ->where('refund_method', 'cash')
                ->sum('total_amount')
        );
    }

    /**
     * expected_cash = opening_float + cash sales - cash refunds.
     *
     * The whole point of the drawer session. Compared against the counted cash,
     * the difference is the shop's main control against till shrinkage - so this
     * must be derived from the sales themselves, never from a running total that
     * could drift.
     *
     * Refunds default to the session's own cash refunds; pass $cashRefunds to
     * override (a caller that has already computed them, or a test).
     */
    public function expectedCash(DrawerSession $drawer, string|int|float|null $cashRefunds = null): string
    {
        $opening = $drawer->opening_float ?? Money::ZERO;
        $refunds = $cashRefunds ?? $this->cashRefundsTotal($drawer->uuid);

        return Money::sub(
            Money::add($opening, $this->cashSalesTotal($drawer->uuid)),
            $refunds
        );
    }

    // -----------------------------------------------------------------------
    // close
    // -----------------------------------------------------------------------

    /**
     * Count the drawer and close the session.
     *
     * The variance is computed and recorded, never blocked. A short drawer is
     * information the owner needs, and refusing to close until it balances would
     * simply teach staff to type the expected figure.
     *
     * @throws SessionException if nothing is open
     */
    public function close(
        string $userUuid,
        string|int|float $countedCash,
        string $deviceId,
        ?DrawerSession $drawer = null,
        string|int|float|null $cashRefunds = null,
        ?string $notes = null,
    ): DrawerSession {
        $drawer ??= $this->openSession();

        if ($drawer === null) {
            throw new SessionException('no open session to close');
        }

        return DB::transaction(function () use ($drawer, $userUuid, $countedCash, $deviceId, $cashRefunds, $notes) {
            $counted = Money::format($countedCash);
            $expected = $this->expectedCash($drawer, $cashRefunds);

            $drawer->closed_by_user_uuid = $userUuid;
            $drawer->closed_at = BusinessDate::nowUtc();
            $drawer->closing_cash_counted = $counted;
            $drawer->expected_cash = $expected;
            // Negative means the drawer is SHORT. Signed, not absolute: which
            // way it went is the first question anyone asks.
            $drawer->cash_variance = Money::sub($counted, $expected);
            $drawer->status = 'closed';

            if ($notes !== null && $notes !== '') {
                $drawer->notes = $notes;
            }

            // session_date is deliberately not touched: the session stays dated
            // by the day it OPENED, even when it closes after midnight.
            $drawer->save();

            $this->audit->record(
                action: config('pharmacy.audit.session_close'),
                deviceId: $deviceId,
                userUuid: $userUuid,
                sessionUuid: $drawer->uuid,
                details: [
                    'counted' => $counted,
                    'expected' => $expected,
                    'variance' => (string) $drawer->cash_variance,
                ],
            );

            $this->presence->autoCheckOutAll($drawer->uuid);

            Log::info('session closed', [
                'session' => $drawer->uuid,
                'by' => $userUuid,
                'variance' => $drawer->cash_variance,
            ]);

            return $drawer;
        });
    }
}
