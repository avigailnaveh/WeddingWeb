import { Clock, Church, Wine, UtensilsCrossed, Music } from "lucide-react";
import coupleThird from "@/assets/couple-third.jpg";

const events = [
  { time: "19:00", label: "קבלת פנים", icon: Clock },
  { time: "19:30", label: "חופה", icon: Church },
  { time: "20:30", label: "לחיים", icon: Wine },
  { time: "21:00", label: "מנה ראשונה", icon: UtensilsCrossed },
  { time: "23:00", label: "מסיבה", icon: Music },
];

const TimelineSection = () => {
  return (
    <section className="bg-wedding-blush py-12 px-6">
      <div className="max-w-md mx-auto">
        {/* Photo */}
        <div className="w-full h-48 overflow-hidden rounded-lg mb-10">
          <img
            src={coupleThird}
            alt="תמונת זוג"
            className="w-full h-full object-cover"
            loading="lazy"
            width={800}
            height={600}
          />
        </div>

        <h2 className="font-heading text-2xl font-semibold text-foreground text-center mb-8">לוח זמנים</h2>

        <div className="space-y-6">
          {events.map((event, i) => (
            <div key={i} className="flex items-center gap-5 justify-center">
              <event.icon className="w-6 h-6 text-wedding-warm flex-shrink-0" />
              <div className="text-center min-w-[120px]">
                <p className="font-heading text-lg text-primary font-semibold">{event.time}</p>
                <p className="font-body text-sm text-muted-foreground">{event.label}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};

export default TimelineSection;
