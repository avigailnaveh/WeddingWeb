import coupleSecond from "@/assets/couple-second.png";
import { Heart } from "lucide-react";

const CeremonySection = () => {
  return (
    <section className="bg-background">
      <div className="max-w-md mx-auto">
        {/* Photo */}
        <div className="w-full h-full overflow-hidden mb-10">
          <img
            src={coupleSecond}
            alt="תמונת זוג"
            className="w-full h-full object-cover"
            loading="lazy"
            width={800}
            height={600}
          />
        </div>

        {/* Chuppah */}
        <div className="text-center mb-10">
          <Heart className="w-8 h-8 mx-auto text-wedding-warm mb-3" />
          <h2 className="font-heading text-2xl font-semibold text-foreground mb-3">חופה</h2>
          <p className="font-body text-muted-foreground text-sm leading-relaxed">
            אולם "בית רבקה"
            <br />
            כפר חב"ד ב
          </p>
          <a
            href="https://waze.com/ul/hsv8y127fs"
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
