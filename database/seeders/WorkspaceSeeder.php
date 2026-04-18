<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 開発用ユーザーを取得、なければ作成（何度実行しても重複しない）
        $owner = User::firstOrCreate(
            ['email' => 'dev@example.com'],
            [
                'name'              => 'Dev User',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        // ワークスペースを3つ作成
        $workspaces = Workspace::factory(3)->create([
            'owner_id' => $owner->id,
        ]);

        // 各ワークスペースにメンバーを追加（多対多）
        $workspaces->each(function (Workspace $workspace) use ($owner) {
            // オーナーを members に追加（role: owner）
            $workspace->members()->attach($owner->id, ['role' => 'owner']);

            // 追加メンバーを2〜4人生成して attach
            $members = User::factory(rand(2, 4))->create();
            $members->each(function (User $member) use ($workspace) {
                $workspace->members()->attach($member->id, ['role' => 'member']);
            });
        });
    }
}
