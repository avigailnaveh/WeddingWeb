import coupleSecond from "@/assets/couple-second.jpg";
import { Church, PartyPopper } from "lucide-react";

const CeremonySection = () => {
  return (
    <section className="bg-background py-12 px-6">
      <div className="max-w-md mx-auto">
        {/* Photo */}
        <div className="w-full h-64 overflow-hidden rounded-lg mb-10">
          <img
            src={coupleSecond}
            alt="תמונת זוג"
            className="w-full h-full object-cover"
            loading="lazy"
            width={800}
            height={600}
          />
        </div>

        {/* Ceremony */}
        <div className="text-center mb-10">
          <Church className="w-8 h-8 mx-auto text-wedding-warm mb-3" />
          <h2 className="font-heading text-2xl font-semibold text-foreground mb-3">טקס</h2>
          <p className="font-body text-muted-foreground text-sm leading-relaxed">
            21:30 - בית הכנסת הגדול
            <br />
            רחוב הרצל 15, תל אביב
          </p>
          <a
            href="https://maps.google.com"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-block mt-4 px-6 py-2 border border-primary text-primary font-body text-xs tracking-[0.15em] uppercase rounded-sm hover:bg-primary hover:text-primary-foreground transition-colors"
          >
            איך מגיעים
          </a>
        </div>

        {/* Party */}
        <div className="text-center">
          <PartyPopper className="w-8 h-8 mx-auto text-wedding-warm mb-3" />
          <h2 className="font-heading text-2xl font-semibold text-foreground mb-3">מסיבה</h2>
          <p className="font-body text-muted-foreground text-sm leading-relaxed">
            22:00 - אולם האירועים
            <br />
            רחוב הירקון 50, תל אביב
          </p>
          <a
            href="https://maps.google.com"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-block mt-4 px-6 py-2 border border-primary text-primary font-body text-xs tracking-[0.15em] uppercase rounded-sm hover:bg-primary hover:text-primary-foreground transition-colors"
          >
            איך מגיעים
          </a>
        </div>
      </div>
    </section>
  );
};

export default CeremonySection;
