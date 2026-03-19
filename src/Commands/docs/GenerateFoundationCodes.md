# Most common cases

php artisan codes:generate-missing Department
php artisan codes:generate-missing "App\Models\Regions\Department"
php artisan codes:generate-missing Invoice --column=invoice_number

## Force regeneration

php artisan codes:generate-missing User --force --column=employee_code

## Safe testing

php artisan codes:generate-missing Project --dry-run

## Larger datasets

php artisan codes:generate-missing Order --chunk=500 --memory-limit=1024M

## Naming variants you might also consider

protected $signature = 'codes:fill        {model} ...';
protected $signature = 'codes:generate    {model} ...';
protected $signature = 'make:codes        {model} ...';
protected $signature = 'model:fill-codes  {model} ...';
