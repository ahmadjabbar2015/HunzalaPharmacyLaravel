<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\SessionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The column set every syncable table carries (see BaseModel).
         *
         * dateTime, not timestamp: MySQL's TIMESTAMP type can silently attach
         * ON UPDATE CURRENT_TIMESTAMP to the first such column in a table, which
         * would bump created_at behind our back and change the record hash.
         */
        Blueprint::macro('syncColumns', function (): void {
            /** @var Blueprint $this */
            $this->uuid('uuid')->primary();                     // RULE 1
            $this->dateTime('created_at')->index();
            $this->dateTime('updated_at')->index();
            $this->string('origin_device_id', 20)->index();
            $this->boolean('is_deleted')->default(false);       // RULE 4
            $this->dateTime('deleted_at')->nullable();
            $this->uuid('deleted_by_user_uuid')->nullable();
            $this->boolean('is_synced')->default(false);        // local only
        });

        $this->registerBladeDirectives();
        $this->shareLayoutState();
    }

    private function registerBladeDirectives(): void
    {
        /*
         * @active('pos.*') marks the current nav item.
         *
         * A directive rather than a ternary repeated in the navbar: there are a
         * dozen nav links, and one of them getting the condition subtly wrong is
         * the kind of thing nobody notices for months.
         */
        Blade::directive('active', fn (string $patterns) => "<?php echo request()->routeIs({$patterns}) ? 'active' : ''; ?>");
    }

    /**
     * State the layout needs on every authenticated page.
     *
     * A view composer rather than passing these from thirty controllers, each of
     * which would eventually forget one and render a navbar with no drawer badge.
     */
    private function shareLayoutState(): void
    {
        View::composer('layouts.app', function ($view) {
            /*
             * Wrapped, because the layout is also what renders an error page. If
             * the database is unreachable, these queries would throw INSIDE the
             * error template and turn a readable "cannot connect" into a blank
             * white page - the single least helpful failure mode there is.
             */
            try {
                $sessions = app(SessionService::class);

                $view->with([
                    'shopName' => Setting::query()->value('shop_name') ?: config('app.name'),
                    'openDrawer' => $sessions->openSession(),
                    'staleDrawer' => $sessions->sessionNeedingClose(),
                ]);
            } catch (Throwable) {
                $view->with([
                    'shopName' => config('app.name'),
                    'openDrawer' => null,
                    'staleDrawer' => null,
                ]);
            }
        });
    }
}
