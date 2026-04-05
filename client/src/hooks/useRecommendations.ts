import { useEffect, useMemo, useRef, useState } from "react";
import {
  createRecommendation,
  deleteRecommendation,
  fetchRecommendations,
  patchRecommendation,
  RecommendationCounts,
} from "@/api/recommendations";

export type RecommendationStatus = "pending" | "approved" | "rejected";

export type Recommendation = {
  id: number;
  text: string;
  professional_id?: number | null;

  status: RecommendationStatus;

  // UI mapping: 2=pos, 0=neg, 1=neu
  sentimentPred?: 0 | 1 | 2 | null;
  sentimentConfidence?: number | null;

  sentimentProbs?: {
    pos?: number;
    neg?: number;
    neu?: number;
  } | null;

  worksWithChildren?: boolean | null;
  reviewerNote?: string | null;
  createdAt?: string;
};

/**
 * Patch אחיד לשני שימושים:
 * - שמירה ב-localStorage (כי אין PATCH ב-Agent)
 * - העברה ל-patchRecommendation (גם אם הוא no-op כרגע)
 */
export type RecommendationPatch = Partial<
  Pick<
    Recommendation,
    "status" | "reviewerNote" | "sentimentPred" | "sentimentConfidence" | "worksWithChildren"
  >
>;

export function useRecommendations() {
  const [rows, setRows] = useState<Recommendation[]>([]);
  const rowsRef = useRef<Recommendation[]>([]);
  const [counts, setCounts] = useState<RecommendationCounts>({
    pending: 0,
    approved: 0,
    rejected: 0,
    total: 0,
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string>("");

  useEffect(() => {
    rowsRef.current = rows;
  }, [rows]);

  const reload = async () => {
    setLoading(true);
    setError("");
    try {
      const { recommendations, counts: serverCounts } = await fetchRecommendations();
      setRows(recommendations);
      setCounts(serverCounts);
    } catch (e: any) {
      setError(e?.message || "Failed to load recommendations");
      setRows([]);
      setCounts({ pending: 0, approved: 0, rejected: 0, total: 0 });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const optimisticPatch = async (id: number, patch: RecommendationPatch) => {
    // optimistic update
    const prev = rowsRef.current;

    setRows((cur) => cur.map((r) => (r.id === id ? { ...r, ...patch } : r)));

    try {
      // Update in database
      await patchRecommendation(id, patch);
    } catch (e) {
      // rollback
      setRows(prev);
      throw e;
    }
  };

  const actions = useMemo(() => {
    return {
      /**
       * יצירה ב-DB (Agent עושה ingest + analysis)
       */
      async add(params: { text: string; professional_id?: number | null; member_id?: number | null }) {
        const created = await createRecommendation(params);
        setRows((prev) => [created, ...prev]);
        return created;
      },

      /** עדכון כללי */
      async update(id: number, patch: RecommendationPatch) {
        await optimisticPatch(id, patch);
      },

      async approve(id: number) {
        await optimisticPatch(id, { status: "approved" });
        // Reload to get updated counts from server
        await reload();
      },
      async reject(id: number) {
        // Delete from database instead of just marking as rejected
        const prev = rowsRef.current;
        
        // Optimistically remove from UI
        setRows((cur) => cur.filter((r) => r.id !== id));
        
        try {
          await deleteRecommendation(id);
          // Reload to get updated counts from server
          await reload();
        } catch (e) {
          // Rollback on error
          setRows(prev);
          throw e;
        }
      },
      async toPending(id: number) {
        await optimisticPatch(id, { status: "pending" });
      },

      async refresh() {
        await reload();
      },
    };
  }, []);

  return { rows, counts, actions, loading, error };
}