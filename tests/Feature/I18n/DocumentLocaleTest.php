<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Sales\InvoiceSharedNotification;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    Locales::apply('en');
});

function makeLocaleInvoice(object $test, Contact $customer): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-I18N-1',
        'invoice_date' => CarbonImmutable::create(2026, 9, 7),
        'due_date' => CarbonImmutable::create(2026, 10, 7),
    ]);

    $invoice->lines()->create([
        'account_id' => $test->income->id,
        'description' => 'Consulting',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    return $invoice->fresh(['contact']);
}

it('resolves document locale from the contact then the company', function () {
    $company = $this->company;
    $company->update(['locale' => 'fr']);

    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);

    expect(Locales::forDocument($customer->fresh(), $company->fresh()))->toBe('fr');

    $customer->update(['locale' => 'en']);

    expect(Locales::forDocument($customer->fresh(), $company->fresh()))->toBe('en');
});

it('renders invoice chrome in the customer document language', function () {
    $this->company->update(['locale' => 'en']);
    $customer = Contact::create([
        'display_name' => 'Acme',
        'is_customer' => true,
        'locale' => 'fr',
    ]);
    $invoice = makeLocaleInvoice($this, $customer);

    $html = app(InvoicePdfRenderer::class)->html($this->company, $invoice);

    expect($html)->toContain('FACTURE')
        ->and($html)->toContain('Facturer à')
        ->and($html)->not->toContain('>INVOICE<');
});

it('falls back to the organization document language when the contact has none', function () {
    $this->company->update(['locale' => 'fr']);
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $invoice = makeLocaleInvoice($this, $customer);

    $html = app(InvoicePdfRenderer::class)->html($this->company, $invoice);

    expect($html)->toContain('FACTURE');
});

it('does not use the staff UI locale for the invoice PDF', function () {
    $this->user->update(['locale' => 'fr']);
    $this->company->update(['locale' => 'en']);
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $invoice = makeLocaleInvoice($this, $customer);

    Locales::apply('fr');

    $html = app(InvoicePdfRenderer::class)->html($this->company, $invoice);

    expect($html)->toContain('INVOICE')
        ->and($html)->not->toContain('FACTURE');
});

it('writes the invoice email in the customer document language', function () {
    $customer = Contact::create([
        'display_name' => 'Acme',
        'email' => 'buyer@acme.test',
        'is_customer' => true,
        'locale' => 'fr',
    ]);
    $invoice = makeLocaleInvoice($this, $customer);
    $companyName = $this->company->brand_name ?: $this->company->name;

    $mail = (new InvoiceSharedNotification($invoice, $this->company, 'http://pay.test/x', 'Please pay.'))
        ->toMail($customer);

    expect($mail->subject)->toBe('Facture INV-I18N-1 de '.$companyName);
});

it('restores the previous locale after rendering a document', function () {
    Locales::apply('en');
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true, 'locale' => 'fr']);
    $invoice = makeLocaleInvoice($this, $customer);

    app(InvoicePdfRenderer::class)->html($this->company, $invoice);

    expect(app()->getLocale())->toBe('en');
});

it('saves the staff interface language on the profile page', function () {
    Livewire::test('pages::settings.profile')
        ->set('locale', 'fr')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($this->user->fresh()->locale)->toBe('fr');
});

it('applies ?lang= for guests and shows the language switcher', function () {
    auth()->logout();

    $this->get(route('login', ['lang' => 'fr']))
        ->assertOk()
        ->assertSee('data-test="language-switcher"', false)
        ->assertSee('Français');
});
