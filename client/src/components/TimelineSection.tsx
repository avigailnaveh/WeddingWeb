import { Clock, Wine, UtensilsCrossed, Music } from "lucide-react";
import coupleThird from "@/assets/couple-third.png";

const ChuppahIcon = ({ className }: { className?: string }) => (
  <svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
    className={className}
    aria-hidden="true"
  >
    {/* גג החופה */}
    <path d="M5 8H19" />
    <path d="M6 8L8 5H16L18 8" />

    {/* עמודים */}
    <path d="M7 8V19" />
    <path d="M17 8V19" />

    {/* בד/קישוט */}
    <path d="M9 8V10" />
    <path d="M12 8V11" />
    <path d="M15 8V10" />

    {/* בסיס */}
    <path d="M5.5 19H8.5" />
    <path d="M15.5 19H18.5" />
  </svg>
);

const events = [
  { time: "19:00", label: "קבלת פנים", icon: Clock },
  { time: "19:30", label: "חופה", icon: ChuppahIcon },
  { time: "20:30", label: "לחיים", icon: Wine },
  { time: "21:00", label: "מנה ראשונה", icon: UtensilsCrossed },
  { time: "23:00", label: "מסיבה", icon: Music },
];

const TimelineSection = () => {
  return (
    <section className="bg-wedding-blush">
      <div className="max-w-md mx-auto">
        <div className="w-full h-full overflow-hidden mb-10">
          <img
            src={coupleThird}
            alt="תמונת זוג"
            className="w-full h-full object-cover"
            loading="lazy"
            width={800}
            height={800}
          />
        </div>

        <h2 className="font-heading text-2xl font-semibold text-foreground text-center mb-8">
          לוח זמנים
        </h2>

        <div className="space-y-6">
          {events.map((event, i) => (
            <div key={i} className="flex items-center gap-5 justify-center">
              <event.icon className="w-6 h-6 text-wedding-warm flex-shrink-0" />
              <div className="text-center min-w-[120px]">
                <p className="font-heading text-lg text-primary font-semibold">
                  {event.time}
                </p>
                <p className="font-body text-sm text-muted-foreground">
                  {event.label}
                </p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};

export default TimelineSection;