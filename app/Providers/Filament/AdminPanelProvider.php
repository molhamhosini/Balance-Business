<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Tenancy\EditCompanyProfile;
use App\Filament\Admin\Pages\Tenancy\RegisterCompany;
use App\Http\Middleware\SetLocale;
use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(fn () => __('Business Balance'))
            ->colors([
                'primary' => Color::hex('#0f766e'),
                'gray' => Color::Slate,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
                'info' => Color::Sky,
            ])
            ->font('IBM Plex Sans Arabic')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth("17rem")
            ->spa()
            ->databaseNotifications(false)
            ->tenant(Company::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterCompany::class)
            ->tenantProfile(EditCompanyProfile::class)
            ->navigationGroups([
                NavigationGroup::make()->label(fn () => __('Accounting')),
                NavigationGroup::make()->label(fn () => __('Reports')),
                NavigationGroup::make()->label(fn () => __('Configuration')),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label(fn () => app()->getLocale() === 'ar' ? 'English' : 'العربية')
                    ->icon('heroicon-o-language')
                    ->url(fn () => route('locale.switch', app()->getLocale() === 'ar' ? 'en' : 'ar')),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.components.styles'))
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
