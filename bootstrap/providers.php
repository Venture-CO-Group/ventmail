<?php

declare(strict_types=1);
use App\Modules\Analytics\Providers\AnalyticsServiceProvider;
use App\Modules\Api\Providers\ApiServiceProvider;
use App\Modules\Audience\Providers\AudienceServiceProvider;
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Campaign\Providers\CampaignServiceProvider;
use App\Modules\Consent\Providers\ConsentServiceProvider;
use App\Modules\Deliverability\Providers\DeliverabilityServiceProvider;
use App\Modules\Domains\Providers\DomainsServiceProvider;
use App\Modules\Events\Providers\EventsServiceProvider;
use App\Modules\Forms\Providers\FormsServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Inbound\Providers\InboundServiceProvider;
use App\Modules\IpPools\Providers\IpPoolsServiceProvider;
use App\Modules\Journey\Providers\JourneyServiceProvider;
use App\Modules\Logs\Providers\LogsServiceProvider;
use App\Modules\Messages\Providers\MessagesServiceProvider;
use App\Modules\Sending\Providers\SendingServiceProvider;
use App\Modules\Smtp\Providers\SmtpServiceProvider;
use App\Modules\StoredTemplates\Providers\StoredTemplatesServiceProvider;
use App\Modules\SuperAdmin\Providers\SuperAdminServiceProvider;
use App\Modules\Suppression\Providers\SuppressionServiceProvider;
use App\Modules\Template\Providers\TemplateServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Modules\Validation\Providers\ValidationServiceProvider;
use App\Modules\VentSec\Providers\VentSecServiceProvider;
use App\Modules\Webhooks\Providers\WebhooksServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\OutboundMailGuardServiceProvider;

/*
 | Modulmonolit: minden app/Modules/<Name> modul saját ServiceProviderrel regisztrál.
 | Új modul felvételekor ide is be kell kerülnie (a ModuleScaffoldTest ezt kikényszeríti).
 */

return [
    AppServiceProvider::class,
    // Golden rule 2: futásidőben metszi a stock Laravel mail-transportokat (megkerülés-védelem).
    OutboundMailGuardServiceProvider::class,

    // közös motor
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    TenancyServiceProvider::class,
    DomainsServiceProvider::class,
    IpPoolsServiceProvider::class,
    SendingServiceProvider::class,
    EventsServiceProvider::class,
    SuppressionServiceProvider::class,

    // PLATFORM (Mailgun-kiváltás)
    MessagesServiceProvider::class,
    SmtpServiceProvider::class,
    InboundServiceProvider::class,
    LogsServiceProvider::class,
    ValidationServiceProvider::class,
    WebhooksServiceProvider::class,
    DeliverabilityServiceProvider::class,
    StoredTemplatesServiceProvider::class,

    // STUDIO (Mailchimp-kiváltás)
    AudienceServiceProvider::class,
    ConsentServiceProvider::class,
    TemplateServiceProvider::class,
    CampaignServiceProvider::class,
    JourneyServiceProvider::class,
    FormsServiceProvider::class,
    AnalyticsServiceProvider::class,

    // ventSec-kötés (m2m token, ítélet-ellenőrzés, VSES események)
    VentSecServiceProvider::class,

    // zárás
    BillingServiceProvider::class,
    SuperAdminServiceProvider::class,
    ApiServiceProvider::class,
];
