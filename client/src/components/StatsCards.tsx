import { Card, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";

export function StatsCards({ counts }: { counts: any }) {
  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
      {[
        { label: 'ממתין', value: counts.pending },
        { label: 'מאושר', value: counts.approved },
        // { label: 'נדחה', value: counts.rejected },
        { label: 'סה"כ', value: counts.total },
      ].map((c) => (
        <Card key={c.label} className="rounded-2xl shadow-sm">
          <CardHeader className="pb-2">
            <CardDescription>{c.label}</CardDescription>
            <CardTitle className="text-2xl">{c.value}</CardTitle>
          </CardHeader>
        </Card>
      ))}
    </div>
  );
}
