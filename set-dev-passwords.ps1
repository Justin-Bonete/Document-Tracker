# Sets ONE password on all four dev users (admin, records, head, staff). Run from the project folder.
$sec = Read-Host "Choose ONE password for all dev users (letters and numbers only)" -AsSecureString
$env:DEV_PW = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))

$php = @'
<?php
use App\Models\User;
$pw = getenv('DEV_PW');
foreach (['admin@example.com', 'records@example.com', 'head@example.com', 'staff@example.com'] as $e) {
    $u = User::where('email', $e)->first();
    if ($u) { $u->password = $pw; $u->status = 'active'; $u->save(); echo "$e: ok\n"; }
    else    { echo "$e: NOT FOUND (run: php artisan db:seed --class=RbacSeeder)\n"; }
}
'@
Set-Content -Path .\_setpw.php -Value $php -Encoding ascii
php artisan tinker --execute="include '_setpw.php';"
Remove-Item .\_setpw.php
Remove-Item Env:DEV_PW
