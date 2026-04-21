<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, router } from '@inertiajs/vue3'

// Props の型定義
interface Project {
    id: number
    name: string
    description: string | null
    color: string
    status: string
    creator: { id: number; name: string }
}

interface Workspace {
    id: number
    name: string
    slug: string
}

const props = defineProps<{
    workspace: Workspace
    projects: Project[]
}>()

// 作成フォーム
const form = useForm({
    name: '',
    description: '',
    color: '#6366f1',
})

const submit = () => {
    form.post(route('workspaces.projects.store', props.workspace.id), {
        onSuccess: () => form.reset(),
    })
}

const deleteProject = (project: Project) => {
    if (confirm(`「${project.name}」を削除しますか？`)) {
        router.delete(route('workspaces.projects.destroy', [props.workspace.id, project.id]))
    }
}
</script>

<template>
<Head :title="`${workspace.name} - プロジェクト`" />

<AuthenticatedLayout>
    <template #header>
    <h2 class="text-xl font-semibold text-gray-800">
        {{ workspace.name }} / プロジェクト
    </h2>
    </template>

    <div class="py-8 max-w-4xl mx-auto px-4">

    <!-- プロジェクト作成フォーム -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-medium mb-4">新規プロジェクト</h3>
        <form @submit.prevent="submit" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">プロジェクト名 *</label>
            <input
            v-model="form.name"
            type="text"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
            placeholder="例：ウェブサイトリニューアル"
            />
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
            {{ form.errors.name }}
            </p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea
            v-model="form.description"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
            rows="2"
            />
        </div>
        <div class="flex items-center gap-4">
            <div>
            <label class="block text-sm font-medium text-gray-700">カラー</label>
            <input v-model="form.color" type="color" class="mt-1 h-9 w-16 rounded cursor-pointer" />
            </div>
            <button
            type="submit"
            :disabled="form.processing"
            class="mt-5 px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-50"
            >
            作成する
            </button>
        </div>
        </form>
    </div>

    <!-- プロジェクト一覧 -->
    <div class="space-y-3">
        <div
        v-for="project in projects"
        :key="project.id"
        class="bg-white rounded-lg shadow p-5 flex items-center justify-between"
        >
        <div class="flex items-center gap-3">
            <!-- カラードット -->
            <span
            class="w-3 h-3 rounded-full flex-shrink-0"
            :style="{ backgroundColor: project.color }"
            />
            <div>
            <p class="font-medium text-gray-900">{{ project.name }}</p>
            <p class="text-sm text-gray-500">作成者：{{ project.creator.name }}</p>
            </div>
        </div>
        <button
            @click="deleteProject(project)"
            class="text-sm text-red-500 hover:text-red-700"
        >
            削除
        </button>
        </div>

        <p v-if="projects.length === 0" class="text-center text-gray-400 py-8">
        プロジェクトがまだありません
        </p>
    </div>

    </div>
</AuthenticatedLayout>
</template>
