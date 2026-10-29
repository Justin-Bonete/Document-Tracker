<?php
use App\Models\User;
use Illuminate\Support\Facades\Hash;
$pw = 'MyNewPass123!';
foreach (['admin@example.com', 'records@example.com'] as $email) {
    $u = User::where('email', $email)->first();
    if ($u) {
        $u->forceFill(['password' => Hash::make($pw), 'status' => 'active'])->save();
        echo "$email: password reset\n";
    } else {
        echo "$email: NOT FOUND\n";
    }
}
