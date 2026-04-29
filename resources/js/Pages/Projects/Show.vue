<template>
    <div class="p-6">
        <h1 class="text-2xl font-bold mb-4">{{ project.name }}</h1>

        <!-- リアルタイム通知バナー -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
        <div
            v-if="notification"
            class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded mb-4"
        >
            {{ notification }}
        </div>
        </Transition>

        <!-- タスク一覧 -->
        <div class="space-y-2">
            <div
                v-for="task in localTasks"
                :key="task.id"
                class="flex items-center justify-between border rounded p-3 bg-white shadow-sm"
            >
                <span class="font-medium">{{ task.title }}</span>
                <span
                class="text-xs px-2 py-1 rounded"
                :class="statusClass(task.status)"
                >
                {{ task.status }}
                </span>
            </div>

            <p v-if="localTasks.length === 0" class="text-gray-400 text-sm">
                タスクがありません
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { useEcho } from '@laravel/echo-vue';
import type { TaskStatusChangedPayload } from '@/types';

// ---- Props ----
interface Task {
    id: number;
    title: string;
    status: string;
}

interface Project {
    id: number;
    name: string;
    workspace_id: number;
}

const props = defineProps<{
    project: Project;
    tasks: Task[];
}>();

// ---- State ----
const localTasks = ref<Task[]>([...props.tasks]);
const notification = ref<string | null>(null);
let notificationTimer: ReturnType<typeof setTimeout> | null = null;

// ---- Helpers ----
function showNotification(message: string): void {
    notification.value = message;
    if (notificationTimer) clearTimeout(notificationTimer);
    notificationTimer = setTimeout(() => {
        notification.value = null;
    }, 4000);
}

function statusClass(status: string): string {
    const map: Record<string, string> = {
        todo:        'bg-gray-100 text-gray-600',
        in_progress: 'bg-yellow-100 text-yellow-700',
        done:        'bg-green-100 text-green-700',
    };
    return map[status] ?? 'bg-gray-100 text-gray-500';
}

// ---- WebSocket ----
const { stopListening } = useEcho<TaskStatusChangedPayload>(
    `workspace.${props.project.workspace_id}`,
    '.task.status.changed',
    (e) => {
        // ローカルのタスク一覧を更新
        const target = localTasks.value.find(t => t.id === e.task.id);
        if (target) {
        target.status = e.task.status;
        }

        showNotification(
        `${e.changed_by.name} が「${e.task.title}」を ${e.task.old_status} → ${e.task.status} に変更しました`,
        );
    },
);

onUnmounted(() => {
    stopListening();
    if (notificationTimer) clearTimeout(notificationTimer);
});
</script>
