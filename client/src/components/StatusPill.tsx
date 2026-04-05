import { CheckCircle2, XCircle, Clock } from "lucide-react";

export function StatusPill({ status }: { status: "pending" | "approved" | "rejected" }) {
  if (status === "approved")
    return (
      <div className="inline-flex items-center gap-2 text-sm">
        <CheckCircle2 className="h-4 w-4" /> מאושר
      </div>
    );
  if (status === "rejected")
    return (
      <div className="inline-flex items-center gap-2 text-sm">
        <XCircle className="h-4 w-4" /> נדחה
      </div>
    );
  return (
    <div className="inline-flex items-center gap-2 text-sm">
      <Clock className="h-4 w-4" /> ממתין לאישור
    </div>
  );
}
