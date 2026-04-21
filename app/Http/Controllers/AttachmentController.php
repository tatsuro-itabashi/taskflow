<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    /**
     * ファイルをアップロードしてタスクに紐付ける
     */
    public function store(Request $request, Task $task): RedirectResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',                                    // 10MB 以内
                'mimes:jpeg,png,webp,gif,pdf,zip,txt,doc,docx,xls,xlsx',
            ],
        ]);

        $uploadedFile = $request->file('file');

        // ファイルを保存（task_id ごとにフォルダを分けて整理）
        $path = $uploadedFile->store("attachments/task_{$task->id}", 'public');

        $task->attachments()->create([
            'user_id'   => $request->user()->id,
            'filename'  => $uploadedFile->getClientOriginalName(), // 元のファイル名
            'path'      => $path,
            'mime_type' => $uploadedFile->getMimeType(),
            'size'      => $uploadedFile->getSize(),
        ]);

        return back()->with('success', 'ファイルを添付しました');
    }

    /**
     * 添付ファイルを削除する
     */
    public function destroy(Attachment $attachment): RedirectResponse
    {
        // アップロードしたユーザー本人のみ削除可能
        $this->authorize('delete', $attachment);

        // Storage からも削除
        if (Storage::disk('public')->exists($attachment->path)) {
            Storage::disk('public')->delete($attachment->path);
        }

        $attachment->delete();

        return back()->with('success', 'ファイルを削除しました');
    }
}
