<?php

namespace App\Providers;

use App\Policies\CompanyMemberPolicy;
use Illuminate\Support\Facades\Gate;
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
        // Member administration targets User models without replacing a UserPolicy.
        Gate::define('viewAnyCompanyMember', [CompanyMemberPolicy::class, 'viewAny']);
        Gate::define('viewCompanyMember', [CompanyMemberPolicy::class, 'view']);
        Gate::define('manageCompanyMember', [CompanyMemberPolicy::class, 'manage']);
    }
}
