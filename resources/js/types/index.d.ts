export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

// ブロードキャストイベントのペイロード型
export interface TaskStatusChangedPayload {
    task: {
        id: number;
        title: string;
        status: string;
        old_status: string;
    };
    changed_by: {
        id: number;
        name: string;
    };
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
};
