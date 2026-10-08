"use client";

import { authed, authedForm } from "@/lib/auth";

/**
 * آپلود از مسیر پروکسی بک‌اند (مطمئن در هر شبکه):
 * presign رکورد را می‌سازد (id فوری برای MediaPicker)، بعد بایت‌ها با
 * multipart به همان رکورد POST می‌شوند. بک‌اند روی S3/MinIO داخلی می‌نویسد.
 */
export async function presignAndUpload(file: File): Promise<{
  id: number;
  path: string;
  uploaded: boolean;
}> {
  const presign = await authed<{
    id: number;
    path: string;
  }>("/v1/admin/media/presign", {
    method: "POST",
    body: { filename: file.name, mime: file.type || "application/octet-stream", size: file.size },
  });
  try {
    const form = new FormData();
    form.append("file", file, file.name);
    await authedForm(`/v1/admin/media/${presign.id}/content`, form);
    return { id: presign.id, path: presign.path, uploaded: true };
  } catch {
    return { id: presign.id, path: presign.path, uploaded: false };
  }
}
