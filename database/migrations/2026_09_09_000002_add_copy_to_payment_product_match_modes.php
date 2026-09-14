<?php

use App\Models\SystemConfig;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $modes = SystemConfig::getArray('payment.product_match_modes', ['MATCH', 'CREATE', 'VIRTUAL']);

        if (! in_array('COPY', $modes, true)) {
            $modes[] = 'COPY';
        }

        SystemConfig::set('payment.product_match_modes', $modes);
    }

    public function down(): void
    {
        $modes = SystemConfig::getArray('payment.product_match_modes', ['MATCH', 'CREATE', 'VIRTUAL', 'COPY']);

        SystemConfig::set('payment.product_match_modes', array_values(array_diff($modes, ['COPY'])));
    }
};
