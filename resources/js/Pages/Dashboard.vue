<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">ダッシュボード</h2>
        </template>

        <div class="py-8 px-6 space-y-8">

            <!-- ワークスペース統計カード -->
            <section>
                <h3 class="text-lg font-medium mb-4">ワークスペース概要</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="ws in stats"
                        :key="ws.workspace_id"
                        class="bg-white rounded-lg shadow p-5 border border-gray-100"
                    >
                        <h4 class="font-semibold text-gray-700 mb-3">{{ ws.workspace_name }}</h4>

                        <div class="grid grid-cols-2 gap-3 text-center">
                            <div class="bg-gray-50 rounded p-2">
                                <p class="text-2xl font-bold text-gray-800">{{ ws.projects_count }}</p>
                                <p class="text-xs text-gray-500">プロジェクト</p>
                            </div>
                            <div class="bg-gray-50 rounded p-2">
                                <p class="text-2xl font-bold text-gray-800">{{ ws.total_tasks }}</p>
                                <p class="text-xs text-gray-500">総タスク</p>
                            </div>
                        </div>

                        <!-- タスク進捗バー -->
                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-gray-500 mb-1">
                                <span>進捗</span>
                                <span>{{ ws.done_count }} / {{ ws.total_tasks }} 完了</span>
                            </div>
                            <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-green-500 rounded-full transition-all"
                                    :style="{ width: progressPercent(ws) + '%' }"
                                />
                            </div>
                        </div>

                        <!-- ステータス内訳 -->
                        <div class="mt-3 flex gap-2 text-xs">
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded">
                                未着手 {{ ws.todo_count }}
                            </span>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded">
                                進行中 {{ ws.in_progress_count }}
                            </span>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded">
                                完了 {{ ws.done_count }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 最近のアクティビティ -->
            <section>
                <h3 class="text-lg font-medium mb-4">最近のアクティビティ</h3>
                <div class="bg-white rounded-lg shadow border border-gray-100 divide-y">
                    <div
                        v-for="log in recentActivity"
                        :key="log.id"
                        class="px-5 py-3 flex items-center gap-3"
                    >
                        <span class="text-lg">
                            {{ actionIcon(log.action) }}
                        </span>
                        <div class="flex-1">
                            <p class="text-sm text-gray-800">
                                <span class="font-medium">{{ log.user_name }}</span>
                                が{{ log.description }}
                            </p>
                        </div>
                        <span class="text-xs text-gray-400 shrink-0">{{ log.created_at }}</span>
                    </div>

                    <div v-if="recentActivity.length === 0" class="px-5 py-8 text-center text-sm text-gray-400">
                        アクティビティはありません
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

interface WorkspaceStat {
    workspace_id: number;
    workspace_name: string;
    projects_count: number;
    total_tasks: number;
    todo_count: number;
    in_progress_count: number;
    done_count: number;
}

interface ActivityLog {
    id: number;
    description: string;
    user_name: string;
    action: string;
    created_at: string;
}

defineProps<{
    stats: WorkspaceStat[];
    recentActivity: ActivityLog[];
}>();

function progressPercent(ws: WorkspaceStat): number {
    if (ws.total_tasks === 0) return 0;
    return Math.round((ws.done_count / ws.total_tasks) * 100);
}

function actionIcon(action: string): string {
    const icons: Record<string, string> = {
        created: '✅',
        updated: '✏️',
        deleted: '🗑️',
    };
    return icons[action] ?? '📌';
}
</script>
