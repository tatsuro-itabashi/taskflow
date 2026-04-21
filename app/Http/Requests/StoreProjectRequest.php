<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    /**
     * このリクエストを実行できるか（認可チェック）
     * ※ 詳細な認可は Policy に委ねるため、ここでは認証済みかのみチェック
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * エラーメッセージの日本語化
     */
    public function messages(): array
    {
        return [
            'name.required' => 'プロジェクト名は必須です',
            'name.max'      => 'プロジェクト名は255文字以内にしてください',
            'color.regex'   => 'カラーは #RRGGBB 形式で入力してください',
        ];
    }
}
