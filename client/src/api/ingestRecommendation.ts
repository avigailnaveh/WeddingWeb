const PHP_SERVER = (import.meta as any)?.env?.VITE_PHP_SERVER || "http://localhost/myyiiapp/web";

export async function ingestRecommendation(payload: {
  member_id: number;
  professional_id: number;
  rec_description: string;
}) {
  const response = await fetch(`${PHP_SERVER}/index.php?r=recommendation/create`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`Failed to ingest recommendation: ${response.statusText} - ${errorText}`);
  }

  const data = await response.json();

  return {
    ok: data.ok,
    recommendation_id: data.recommendation_id,
    sentiment: data.analysis?.sentiment ?? null,
    sentiment_confidence: data.analysis?.sentiment_confidence ?? null,
  };
}