import { SearchResults } from "@/components/search/SearchResults";

/** صفحه نتایج جستجو — admin/search?q=… (گروه‌بندی + هایلایت + حالت خالی). */
export default async function AdminSearchPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  return <SearchResults initialQ={sp.q ?? ""} />;
}
