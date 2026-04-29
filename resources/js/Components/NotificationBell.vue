<template>
    <div class="relative">
        <!-- ベルアイコン -->
        <button
            @click="toggleDropdown"
            class="relative p-2 text-gray-500 hover:text-gray-700"
        >
        <!-- ベルの SVG アイコン -->
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>

        <!-- 未読バッジ -->
        <span
            v-if="unreadCount > 0"
            class="absolute top-1 right-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-xs text-white"
        >
            {{ unreadCount > 9 ? '9+' : unreadCount }}
        </span>
        </button>

        <!-- 通知ドロップダウン -->
        <div
            v-if="isOpen"
            class="absolute right-0 mt-2 w-80 rounded-lg border bg-white shadow-lg z-50"
        >
        <div class="flex items-center justify-between border-b px-4 py-3">
            <span class="font-semibold text-sm">通知</span>
            <button
                v-if="unreadCount > 0"
                @click="markAllAsRead"
                class="text-xs text-blue-500 hover:underline"
                >
                全て既読にする
            </button>
        </div>

        <ul class="max-h-80 overflow-y-auto divide-y">
            <li
                v-for="n in notifications"
                :key="n.id"
                @click="markAsRead(n.id)"
                class="px-4 py-3 hover:bg-gray-50 cursor-pointer"
            >
                <p class="text-sm text-gray-800">
                    <span class="font-medium">{{ n.data.assigned_by.name }}</span>
                    さんが「{{ n.data.task_title }}」を割り当てました
                </p>
                <p class="text-xs text-gray-400 mt-1">{{ n.created_at }}</p>
            </li>

            <li v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
            未読通知はありません
            </li>
        </ul>
        </div>
    </div>
</template>

<script setup lang="ts">
    import { ref, onMounted } from 'vue';
    import axios from 'axios';

    interface NotificationData {
        task_id: number;
        task_title: string;
        project_id: number;
        assigned_by: { id: number; name: string };
    }

    interface Notification {
        id: string;
        data: NotificationData;
        created_at: string;
    }

    const isOpen = ref(false);
    const notifications = ref<Notification[]>([]);
    const unreadCount = ref(0);

    async function fetchNotifications(): Promise<void> {
        const { data } = await axios.get('/api/v1/notifications');
        notifications.value = data.notifications;
        unreadCount.value = data.unread_count;
    }

    async function markAsRead(id: string): Promise<void> {
        await axios.patch(`/api/v1/notifications/${id}/read`);
        notifications.value = notifications.value.filter(n => n.id !== id);
        unreadCount.value = Math.max(0, unreadCount.value - 1);
    }

    async function markAllAsRead(): Promise<void> {
        await axios.patch('/api/v1/notifications/read-all');
        notifications.value = [];
        unreadCount.value = 0;
    }

    function toggleDropdown(): void {
        isOpen.value = !isOpen.value;
        if (isOpen.value) {
        fetchNotifications();
        }
    }

    // 初回マウント時に未読件数だけ取得
    onMounted(() => {
        fetchNotifications();
    });
</script>
