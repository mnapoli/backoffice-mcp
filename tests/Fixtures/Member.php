<?php declare(strict_types=1);

namespace BackofficeMcp\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * A user who logs in to the application, with nothing from Passport on the model.
 */
class Member extends User
{
    protected $table = 'users';
    protected $guarded = [];
}
