<?php

namespace Database\Seeders;

use App\Models\ConnectedAccount;
use Illuminate\Database\Seeder;

/**
 * Pre-populates connected_accounts with the two known brands.
 *
 * The customer_id and conversion_action_resource come from the Google Ads
 * conversion actions we created in the UI. They never change unless we
 * recreate the conversion actions.
 *
 * The refresh_token, oauth_email, and connected_at are populated by the
 * OAuth flow when an operator clicks "Connect" on the Accounts page.
 *
 * Re-running this seeder is safe — it uses updateOrCreate keyed on `brand`
 * and won't overwrite OAuth fields if they're already populated.
 */
class ConnectedAccountSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            [
                'brand' => 'jimny_nz',
                'display_name' => 'Jimny Rentals NZ',
                'customer_id' => '4216879470',
                'conversion_action_resource' => 'customers/4216879470/conversionActions/7591775442',
            ],
            [
                'brand' => 'dream_drives',
                'display_name' => 'Dream Drives',
                'customer_id' => '2074836776',
                'conversion_action_resource' => 'customers/2074836776/conversionActions/7591770600',
            ],
        ];

        foreach ($brands as $b) {
            ConnectedAccount::updateOrCreate(
                ['brand' => $b['brand']],
                array_merge($b, [
                    // Set login_customer_id for both — the MCC is the same for both brands.
                    'login_customer_id' => '1354542535',
                ])
            );
        }

        $this->command->info('Connected accounts seeded: jimny_nz, dream_drives');
        $this->command->info('OAuth not yet completed — visit /dashboard/accounts and click Connect for each.');
    }
}
