import { useState } from "react";
import { Heart } from "lucide-react";
import { supabase } from "@/components/integrations/supabase/client";
import { useToast } from "@/hooks/use-toast";

const RSVPSection = () => {
  const [fullName, setFullName] = useState("");
  const [phone, setPhone] = useState("");
  const [guestsCount, setGuestsCount] = useState(1);
  const [notes, setNotes] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const { toast } = useToast();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!fullName.trim()) return;

    setIsSubmitting(true);
    try {
      const { error } = await supabase.from("rsvp").insert({
        full_name: fullName.trim(),
        phone: phone.trim() || null,
        guests_count: guestsCount,
        notes: notes.trim() || null,
      });

      if (error) throw error;

      setSubmitted(true);
      toast({
        title: "תודה! 🎉",
        description: "אישור ההגעה שלך נשלח בהצלחה",
      });
    } catch {
      toast({
        title: "שגיאה",
        description: "משהו השתבש, נסו שוב",
        variant: "destructive",
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  if (submitted) {
    return (
      <section className="bg-wedding-blush py-12 px-6">
        <div className="max-w-md mx-auto text-center">
          <Heart className="w-10 h-10 mx-auto text-wedding-warm mb-4 fill-wedding-warm" />
          <h2 className="font-heading text-2xl font-semibold text-foreground mb-3">תודה שאישרת הגעה!</h2>
          <p className="font-body text-muted-foreground text-sm">מחכים לראות אתכם 💕</p>
        </div>
      </section>
    );
  }

  return (
    <section className="bg-wedding-blush py-12 px-6">
      <div className="max-w-md mx-auto">
        <div className="text-center mb-8">
          <Heart className="w-8 h-8 mx-auto text-wedding-warm mb-3" />
          <h2 className="font-heading text-2xl font-semibold text-foreground mb-2">אישור הגעה</h2>
          <p className="font-body text-muted-foreground text-sm">נשמח לדעת שאתם מגיעים!</p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="font-body text-sm text-foreground block mb-1">שם מלא *</label>
            <input
              type="text"
              value={fullName}
              onChange={(e) => setFullName(e.target.value)}
              required
              className="w-full px-4 py-2.5 rounded-sm border border-wedding-warm/30 bg-background font-body text-sm focus:outline-none focus:border-wedding-warm transition-colors"
              placeholder="הכניסו את שמכם"
            />
          </div>

          <div>
            <label className="font-body text-sm text-foreground block mb-1">טלפון</label>
            <input
              type="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              className="w-full px-4 py-2.5 rounded-sm border border-wedding-warm/30 bg-background font-body text-sm focus:outline-none focus:border-wedding-warm transition-colors"
              placeholder="מספר טלפון"
              dir="ltr"
            />
          </div>

          <div>
            <label className="font-body text-sm text-foreground block mb-1">מספר אורחים</label>
            <select
              value={guestsCount}
              onChange={(e) => setGuestsCount(Number(e.target.value))}
              className="w-full px-4 py-2.5 rounded-sm border border-wedding-warm/30 bg-background font-body text-sm focus:outline-none focus:border-wedding-warm transition-colors"
            >
              {[1, 2, 3, 4, 5, 6, 7, 8].map((n) => (
                <option key={n} value={n}>{n}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="font-body text-sm text-foreground block mb-1">הערות</label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
              className="w-full px-4 py-2.5 rounded-sm border border-wedding-warm/30 bg-background font-body text-sm focus:outline-none focus:border-wedding-warm transition-colors resize-none"
              placeholder="הגבלות תזונתיות, הערות מיוחדות..."
            />
          </div>

          <button
            type="submit"
            disabled={isSubmitting}
            className="w-full py-3 bg-primary text-primary-foreground font-body text-sm tracking-[0.15em] uppercase rounded-sm hover:opacity-90 transition-opacity disabled:opacity-50"
          >
            {isSubmitting ? "שולח..." : "אישור הגעה"}
          </button>
        </form>
      </div>
    </section>
  );
};

export default RSVPSection;
