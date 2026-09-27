<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

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
    }
}
