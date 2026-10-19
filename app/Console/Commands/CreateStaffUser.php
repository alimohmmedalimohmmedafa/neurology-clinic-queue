<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateStaffUser extends Command
{
    /**
     * @var string
     */
    protected $signature = 'staff:create
        {username? : اسم مستخدم موظف الاستقبال}
        {--name= : الاسم الظاهر للموظف (افتراضيًا اسم المستخدم)}
        {--password= : كلمة المرور (تُطلب بشكل مخفي إن لم تُمرَّر)}';

    /**
     * @var string
     */
    protected $description = 'إنشاء (أو تحديث) حساب دخول موظف الاستقبال';

    public function handle(): int
    {
        $username = (string) ($this->argument('username') ?: $this->ask('اسم المستخدم'));
        $name = (string) ($this->option('name') ?: $this->ask('الاسم الظاهر', $username));
        $password = (string) ($this->option('password') ?: $this->secret('كلمة المرور'));

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            [
                'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['username' => $username],
            [
                'name' => $name !== '' ? $name : $username,
                'email' => $username.'@staff.local',
                'password' => Hash::make($password),
            ]
        );

        $this->info(sprintf(
            '%s حساب الموظف "%s".',
            $user->wasRecentlyCreated ? 'تم إنشاء' : 'تم تحديث',
            $user->username
        ));

        return self::SUCCESS;
    }
}
