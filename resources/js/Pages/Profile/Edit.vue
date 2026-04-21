<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
    auth: { user: { name: string; email: string; avatar_url: string } }
}>();

// アバターフォーム
const avatarForm = useForm({ avatar: null as File | null })

const onFileChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (file) avatarForm.avatar = file
}

const uploadAvatar = () => {
    avatarForm.post(route('avatar.update'), {
        forceFormData: true,   // ファイル送信に必須
    })
}
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Profile
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>

        <!-- アバターアップロード -->
        <section class="space-y-6">
        <header>
            <h2 class="text-lg font-medium text-gray-900">プロフィール画像</h2>
        </header>

        <div class="flex items-center gap-6">
            <img
            :src="auth.user.avatar_url"
            class="w-20 h-20 rounded-full object-cover"
            alt="アバター"
            />

            <form @submit.prevent="uploadAvatar" class="flex items-center gap-3">
            <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                @change="onFileChange"
                class="text-sm text-gray-600"
            />
            <button
                type="submit"
                :disabled="!avatarForm.avatar || avatarForm.processing"
                class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md
                    hover:bg-indigo-700 disabled:opacity-50"
            >
                アップロード
            </button>
            </form>
        </div>
        <p v-if="avatarForm.errors.avatar" class="text-sm text-red-600">
            {{ avatarForm.errors.avatar }}
        </p>
        </section>
    </AuthenticatedLayout>
</template>
