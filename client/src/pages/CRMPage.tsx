import { useMemo, useState } from "react";
import { StatsCards } from "@/components/StatsCards";
import { ExportImportButtons } from "@/components/ExportImportButtons";
import { FiltersBar } from "@/components/FiltersBar";
import { RecommendationCard } from "@/components/RecommendationCard";
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from "@/components/ui/card";
import { useRecommendations } from "@/hooks/useRecommendations";
import type { Recommendation } from "@/hooks/useRecommendations";
import { motion, AnimatePresence } from "framer-motion";
import { AlertCircle } from "lucide-react";

export default function CRMPage() {
  const { rows, counts, actions, loading, error } = useRecommendations();

  const [query, setQuery] = useState("");
  const [filterSentiment, setFilterSentiment] = useState<"all" | "0" | "1" | "2">("all");
  const [sortBy, setSortBy] = useState<"newest" | "oldest" | "confidence">("newest");

  // Filter to show only pending (status = 0) recommendations
  // Note: rows already contains only pending items from server
  const visibleRows = useMemo(() => {
    let list = rows; // All rows are already pending from server

    if (filterSentiment !== "all") {
      const s = Number(filterSentiment);
      list = list.filter((r) => r.sentimentPred === s);
    }

    if (query.trim()) {
      const q = query.trim().toLowerCase();
      list = list.filter((r) => (r.text || "").toLowerCase().includes(q));
    }

    if (sortBy === "newest") {
      list = [...list].sort((a, b) => (b.createdAt || "").localeCompare(a.createdAt || ""));
    } else if (sortBy === "oldest") {
      list = [...list].sort((a, b) => (a.createdAt || "").localeCompare(b.createdAt || ""));
    } else {
      list = [...list].sort(
        (a, b) => (b.sentimentConfidence || 0) - (a.sentimentConfidence || 0)
      );
    }

    return list;
  }, [rows, filterSentiment, query, sortBy]);

  const downloadJSON = () => {
    const payload = { rows, exportedAt: new Date().toISOString() };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `crm_recommendations_${new Date().toISOString().slice(0, 10)}.json`;
    a.click();
    URL.revokeObjectURL(url);
  };

  const importJSON = (file?: File) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const txt = String(reader.result || "");
      try {
        const data = JSON.parse(txt);
        if (!Array.isArray(data?.rows)) return;

        (async () => {
          for (const r of data.rows as Recommendation[]) {
            if (!r?.text) continue;
            try {
              const created = await actions.add({
                text: r.text,
                professional_id: (r as any).professional_id ?? null,
              });
              await actions.update(created.id, {
                status: r.status || "pending",
                reviewerNote: r.reviewerNote || "",
                sentimentPred: r.sentimentPred as any,
                sentimentConfidence: r.sentimentConfidence,
                worksWithChildren: r.worksWithChildren,
              });
            } catch {}
          }
          await actions.refresh();
        })();
      } catch {}
    };
    reader.readAsText(file);
  };

  return (
    <div className="max-w-4xl mx-auto px-2 py-2 space-y-6 mb-8 pt-14">
      {/* Page Header */}
      {/* <div className="bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-2xl p-6 shadow-lg">
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div>
            <h2 className="text-2xl font-bold mb-2">CRM - ניהול המלצות</h2>
            <p className="text-purple-100">
              המלצות שממתינות לאישור (status = 0)
            </p>
            <p className="text-purple-100 text-sm mt-1">
              <AlertCircle className="h-4 w-4 inline ml-1" />
              המלצות חיוביות מאושרות אוטומטית (status = 1)
            </p>
          </div>
          <ExportImportButtons onExport={downloadJSON} onImport={importJSON} onReset={actions.refresh} />
        </div>
      </div> */}

      {/* Stats Cards */}
      <StatsCards counts={counts} />

      {loading && (
        <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
          טוען נתונים מה-DB...
        </div>
      )}

      {error && (
        <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm">
          <div className="font-medium text-red-900">שגיאה בטעינה</div>
          <div className="mt-1 text-red-700">{error}</div>
        </div>
      )}

      {/* Main Content Card */}
      <Card className="rounded-2xl shadow-lg border-slate-200">
        <CardHeader className="pb-3 border-b bg-slate-50/50">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              <CardTitle className="text-slate-900">המלצות ממתינות לאישור</CardTitle>
              {/* <CardDescription>רק המלצות שלא אושרו עדיין (status = 0)</CardDescription> */}
            </div>

            <FiltersBar
              query={query}
              setQuery={setQuery}
              filterSentiment={filterSentiment}
              setFilterSentiment={setFilterSentiment}
              sortBy={sortBy}
              setSortBy={setSortBy}
            />
          </div>
        </CardHeader>

        <CardContent className="p-6">
          <div className="space-y-3">
            <AnimatePresence initial={false}>
              {visibleRows.map((row) => (
                <motion.div
                  key={row.id}
                  layout
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -8 }}
                  transition={{ duration: 0.2 }}
                >
                  <RecommendationCard
                    row={row}
                    onUpdate={actions.update}
                    onApprove={actions.approve}
                    onReject={actions.reject}
                    onToPending={actions.toPending}
                  />
                </motion.div>
              ))}
            </AnimatePresence>

            {visibleRows.length === 0 && (
              <div className="rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 p-12 text-center">
                <div className="text-slate-400 text-lg">אין המלצות ממתינות</div>
                <div className="text-slate-500 text-sm mt-2">
                  כל ההמלצות אושרו או שאין המלצות חדשות
                </div>
              </div>
            )}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
