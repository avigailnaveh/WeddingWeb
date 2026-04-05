import { Badge } from "@/components/ui/badge";
import { SENTIMENT } from "@/config/config";

export function SentimentBadge({
  pred,
  probs,
}: {
  pred: 0 | 1 | 2;
  probs: [number, number, number];
}) {
  const s = SENTIMENT[pred];
  const Icon = s.icon;
  const conf = Math.max(...probs);

  return (
    <div className="flex items-center gap-2">
      <Badge variant={s.badge} className="gap-1">
        <Icon className="h-4 w-4" />
        {s.label}
      </Badge>
    </div>
  );
}
