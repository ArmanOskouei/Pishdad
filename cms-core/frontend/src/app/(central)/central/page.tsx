import { redirect } from "next/navigation";

/** ریدایرکت /central به داشبورد مرکزی ۲.۱ (مسیر کانونی). */
export default function CentralIndex() {
  redirect("/central/dashboard");
}
