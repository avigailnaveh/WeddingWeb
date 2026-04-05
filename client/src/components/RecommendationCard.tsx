import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CheckCircle2, XCircle } from "lucide-react";
import { SentimentBadge } from "./SentimentBadge";
import { StatusPill } from "./StatusPill";
import { Badge } from "@/components/ui/badge";
import type { Recommendation } from "@/hooks/useRecommendations";

type RecommendationPatch = Partial<
  Pick<
    Recommendation,
    "status" | "reviewerNote" | "sentimentPred" | "sentimentConfidence" | "worksWithChildren"
  >
>;

function toPredNum(pred: any): 0 | 1 | 2 {
  if (pred === 0 || pred === 1 || pred === 2) return pred;
  if (pred === "pos") return 2;
  if (pred === "neg") return 0;
  return 1;
}

export function RecommendationCard({
  row,
  onUpdate,
  onApprove,
  onReject,
  onToPending,
}: {
  row: Recommendation;
  onUpdate: (id: number, patch: RecommendationPatch) => void;
  onApprove: (id: number) => void;
  onReject: (id: number) => void;
  onToPending: (id: number) => void;
}) {
  const probs: [number, number, number] = [
    row.sentimentProbs?.pos ?? 0,
    row.sentimentProbs?.neg ?? 0,
    row.sentimentProbs?.neu ?? 0,
  ];

  const predNum = toPredNum((row as any).sentimentPred);

  return (
    <Card className="rounded-2xl shadow-sm">
      <CardContent className="p-4">
        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-3">
              <SentimentBadge pred={predNum} probs={probs} />

              <span className="text-xs text-muted-foreground">
                {row.createdAt ? new Date(row.createdAt).toLocaleString() : ""}
              </span>
            </div>

            <div className="text-sm leading-relaxed whitespace-pre-wrap">{row.text}</div>

            <div className="text-xs text-muted-foreground">
              <StatusPill status={row.status} />
            </div>

            <div className="space-y-2">
              <Label className="text-xs">הערת בודק/ת (אופציונלי)</Label>
              <Textarea
                className="min-h-[70px] rounded-xl"
                value={row.reviewerNote || ""}
                onChange={(e) => onUpdate(row.id, { reviewerNote: e.target.value })}
                placeholder="למה אישרת/דחית?"
              />
            </div>
          </div>

          <div className="flex flex-row gap-2 md:flex-col md:items-stretch">
            {row.status === "pending" ? (
              <>
                <Button className="rounded-xl gap-2" onClick={() => onApprove(row.id)}>
                  <CheckCircle2 className="h-4 w-4" /> אישור
                </Button>
                <Button
                  variant="destructive"
                  className="rounded-xl gap-2"
                  onClick={() => onReject(row.id)}
                >
                  <XCircle className="h-4 w-4" /> דחייה
                </Button>
              </>
            ) : (
              <Button variant="outline" className="rounded-xl" onClick={() => onToPending(row.id)}>
                החזר לממתין
              </Button>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
