import type { Recommendation, RecommendationPatch, RecommendationStatus } from "@/hooks/useRecommendations";

// עכשיו הצד לקוח מדבר רק עם שרת PHP!
const PHP_SERVER = (import.meta as any)?.env?.VITE_PHP_SERVER || "http://localhost/myyiiapp/web";
const DEFAULT_MEMBER_ID = Number((import.meta as any)?.env?.VITE_MEMBER_ID || 1);

type AgentRow = {
  id: number;
  member_id?: number;
  professional_id?: number;
  category_id?: string | null;
  rec_description?: string | null;
  status?: number | null;
  sentiment?: "pos" | "neg" | "neu" | null;
  sentiment_confidence?: number | null;
  analysis_created_at?: string | null;
};

async function httpJson<T>(path: string, init?: RequestInit): Promise<T> {
  const r = await fetch(`${PHP_SERVER}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...(init?.headers || {}),
    },
  });

  if (!r.ok) {
    const t = await r.text().catch(() => "");
    throw new Error(t || `${r.status} ${r.statusText}`);
  }

  return r.json() as Promise<T>;
}

function sentimentToPred(s: AgentRow["sentiment"]): 0 | 1 | 2 | null {
  // UI mapping used by components: 2=pos, 0=neg, 1=neu
  if (s === "pos") return 2;
  if (s === "neg") return 0;
  if (s === "neu") return 1;
  return null;
}

function mapAgentRow(row: AgentRow): Recommendation {
  // Convert database status (0/1) to UI status
  let status: RecommendationStatus = "pending";
  if (row.status === 1) {
    status = "approved";
  } else if (row.status === 0) {
    status = "pending";
  }
  
  return {
    id: Number(row.id),
    text: row.rec_description || "",
    professional_id: row.professional_id ?? null,
    status: status,
    sentimentPred: sentimentToPred(row.sentiment),
    sentimentConfidence: row.sentiment_confidence ?? null,
    createdAt: row.analysis_created_at || undefined,
  };
}

export type RecommendationCounts = {
  pending: number;
  approved: number;
  rejected: number;
  total: number;
};

export async function fetchRecommendations(): Promise<{
  recommendations: Recommendation[];
  counts: RecommendationCounts;
}> {
  // קריאה לשרת PHP במקום ל-Agent
  const raw = await httpJson<any>(`/index.php?r=recommendation/index`);
  
  // Extract pending items (these are the only ones with full data)
  const list: AgentRow[] = raw?.pending ?? [];
  const recommendations = (list || []).map(mapAgentRow);
  
  // Extract counts from server
  const counts: RecommendationCounts = {
    pending: raw?.pending_count ?? 0,
    approved: raw?.approved_count ?? 0,
    rejected: 0, // We don't have rejected status (only 0 and 1)
    total: raw?.total_count ?? 0,
  };
  
  return { recommendations, counts };
}

export async function createRecommendation(params: {
  text: string;
  professional_id?: number | null;
  member_id?: number | null;
}): Promise<Recommendation> {
  const member_id = Number(params.member_id ?? DEFAULT_MEMBER_ID);
  const professional_id = Number(params.professional_id ?? 0);

  if (!professional_id || !Number.isFinite(professional_id)) {
    throw new Error("professional_id is required");
  }

  // קריאה לשרת PHP שידאג לשלוח ל-Agent
  const res = await httpJson<{
    ok: boolean;
    recommendation_id: number;
    analysis?: {
      sentiment: "pos" | "neg" | "neu";
      sentiment_confidence: number;
    };
  }>(`/index.php?r=recommendation/create`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      member_id,
      professional_id,
      rec_description: params.text,
    }),
  });

  if (!res.ok) {
    throw new Error("Failed to create recommendation");
  }

  const sentiment = res.analysis?.sentiment ?? "neu";
  const sentimentConfidence = res.analysis?.sentiment_confidence ?? null;

  // Determine status based on sentiment
  const status: RecommendationStatus = sentiment === "pos" ? "approved" : "pending";

  return {
    id: res.recommendation_id,
    text: params.text,
    professional_id,
    status: status,
    sentimentPred: sentimentToPred(sentiment),
    sentimentConfidence,
  };
}

// Update recommendation status in database
export async function patchRecommendation(id: number, patch: RecommendationPatch): Promise<void> {
  // Convert UI status to database status
  let dbStatus: number | undefined;
  if (patch.status === "approved") {
    dbStatus = 1;
  } else if (patch.status === "pending") {
    dbStatus = 0;
  }
  
  // Only send PATCH if we have a status to update
  if (dbStatus !== undefined) {
    await httpJson(`/index.php?r=recommendation/update&id=${id}`, {
      method: "PATCH",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ status: dbStatus }),
    });
  }
}

// Delete recommendation from database
export async function deleteRecommendation(id: number): Promise<void> {
  await httpJson(`/index.php?r=recommendation/delete&id=${id}`, {
    method: "DELETE",
  });
}

// Get all recommendations for a specific member
export async function fetchMemberRecommendations(memberId: number = DEFAULT_MEMBER_ID): Promise<Record<number, { id: number; text: string }>> {
  const data = await httpJson<any>(`/index.php?r=recommendation/my-recommendations&member_id=${memberId}`);
  return data?.recommendations || {};
}