import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { CheckCircle } from "lucide-react";
import { ingestRecommendation } from "@/api/ingestRecommendation";


export default function SubmitPage() {
  const nav = useNavigate();

  const [text, setText] = useState("");
  const [professionalId, setProfessionalId] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const [memberId, setMemberId] = useState("1");


  const onSubmit = async () => {
    const t = text.trim();
    if (!t) return;

    const pid = professionalId.trim();
    const pidNum = pid ? Number(pid) : null;
    if (pid && (!Number.isFinite(pidNum) || pidNum! <= 0)) {
      setErr("ה-id של הרופא חייב להיות מספר חיובי");
      return;
    }

    setBusy(true);
    setErr("");

    try {
      const t = text.trim();
      const pid = professionalId.trim();
      const mid = memberId.trim();

      const pidNum = pid ? Number(pid) : null;
      const midNum = mid ? Number(mid) : null;

      if (!midNum || !Number.isFinite(midNum) || midNum <= 0) {
        setErr("member_id חייב להיות מספר חיובי");
        return;
      }
      if (!pidNum || !Number.isFinite(pidNum) || pidNum <= 0) {
        setErr("ה-id של הרופא חייב להיות מספר חיובי");
        return;
      }

      const res = await ingestRecommendation({
        member_id: midNum,
        professional_id: pidNum,
        rec_description: t,
      });

      setText("");
      setProfessionalId("");
      nav("/crm");
    } catch (e: any) {
      setErr(e?.message || "שגיאה");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-2xl p-6 shadow-lg">
        <h2 className="text-2xl font-bold mb-2">הכנסת המלצה חדשה</h2>
        <p className="text-blue-100">ההמלצה תישמר בדאטהבייס ותעבור ניתוח אוטומטי</p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Form Card */}
        <Card className="rounded-2xl shadow-lg border-slate-200 lg:col-span-2">
          <CardHeader className="border-b bg-slate-50/50">
            <CardTitle className="text-slate-900">
              טופס הכנסת המלצה
            </CardTitle>
            <CardDescription>מלא את הפרטים ולחץ שליחה</CardDescription>
          </CardHeader>
          <CardContent className="space-y-5 p-6">
            <div className="space-y-2">
              <Label className="text-slate-700 font-medium">Member ID</Label>
              <Input
                className="rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500"
                value={memberId}
                onChange={(e) => setMemberId(e.target.value)}
                placeholder="לדוגמה: 1"
                inputMode="numeric"
              />
            </div>

            <div className="space-y-2">
              <Label className="text-slate-700 font-medium">ID רופא</Label>
              <Input
                className="rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500"
                value={professionalId}
                onChange={(e) => setProfessionalId(e.target.value)}
                placeholder="לדוגמה: 94956"
                inputMode="numeric"
              />
              <p className="text-xs text-slate-500">נשמר בטבלת recommendation כשדה professional_id</p>
            </div>

            <div className="space-y-2">
              <Label className="text-slate-700 font-medium">טקסט ההמלצה</Label>
              <Textarea
                className="min-h-[200px] rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500"
                value={text}
                onChange={(e) => setText(e.target.value)}
                placeholder="הקלד או הדבק כאן את ההמלצה..."
              />
            </div>

            {err && (
              <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm">
                <div className="font-medium text-red-900">שגיאה</div>
                <div className="text-red-700 mt-1">{err}</div>
              </div>
            )}

            <Button 
              className="w-full rounded-xl h-12 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 shadow-md" 
              onClick={onSubmit} 
              disabled={busy || !text.trim()}
            >
              <CheckCircle className="h-5 w-5 ml-2" />
              {busy ? "מעבד ושומר..." : "שליחה"}
            </Button>

            <Separator />
          </CardContent>
        </Card>
      </div>
    </div>
  );
}