import { Frown, Meh, Smile } from "lucide-react";

// ה-endpoint הקיים שלך בצד Yii2 לצ'אט
export const GPT_CHAT_API_URL =
  "http://localhost/myyiiapp/web/index.php?r=member/chat";

export const MEMBER_URL =
  "http://localhost/myyiiapp/web/index.php?r=member/me";

export const STORAGE_KEY = "doctorita_recs_crm_v1";
export const DEFAULT_AUTO_APPROVE_POSITIVE = true;

// 0=שלילי, 1=נייטרלי, 2=חיובי
export const SENTIMENT: Record<
  0 | 1 | 2,
  { label: string; icon: any; badge: "destructive" | "secondary" | "default" }
> = {
  0: { label: "שלילי", icon: Frown, badge: "destructive" },
  1: { label: "נייטרלי", icon: Meh, badge: "secondary" },
  2: { label: "חיובי", icon: Smile, badge: "default" },
};