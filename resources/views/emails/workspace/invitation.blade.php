@component('mail::message')
# ワークスペースへの招待

**{{ $workspaceName }}** にあなたを招待しました。

以下のボタンをクリックして参加してください。

@component('mail::button', ['url' => $inviteUrl, 'color' => 'blue'])
招待を承認する
@endcomponent

このリンクは **{{ $expiresAt }}** まで有効です。

心当たりがない場合は、このメールを無視してください。

Thanks,<br>
{{ config('app.name') }}
@endcomponent
