<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The original notifications.type was created with ->enum(), which on
     * PostgreSQL produces a CHECK constraint restricted to the original five
     * values. The app later began inserting owner-* notification types
     * (owner_payment_received, owner_rent_expiring, owner_rent_due), but the
     * constraint was never widened, so those inserts fail and abort the
     * enclosing transaction (e.g. recording a payment).
     *
     * On PostgreSQL we drop the type CHECK constraint and let the column
     * behave as free text; type is validated in application code.
     * On MySQL the enum already allows the new values, so this is a no-op.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        $constraint = 'notifications_type_check';
        $check = DB::select(
            "select 1 from pg_constraint where conname = ? and conrelid = 'notifications'::regclass",
            [$constraint]
        );

        if ($check) {
            DB::statement("alter table notifications drop constraint \"{$constraint}\"");
        }
    }

    /**
     * Reverse the migrations.
     *
     * The original CHECK constraint that existed before cannot be recreated
     * reliably here (data may contain owner-* values), so this is intentionally
     * a no-op. The column remains text, which is the desired production state.
     */
    public function down(): void
    {
        // Intentionally a no-op.
    }
};
