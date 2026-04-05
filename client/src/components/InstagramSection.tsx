import { Instagram, Music } from "lucide-react";

const InstagramSection = () => {
  return (
    <section className="bg-background py-12 px-6">
      <div className="max-w-md mx-auto text-center">
        {/* Instagram */}
        <Instagram className="w-8 h-8 mx-auto text-wedding-warm mb-3" />
        <h2 className="font-heading text-2xl font-semibold text-foreground mb-3">אינסטגרם</h2>
        <p className="font-body text-muted-foreground text-sm leading-relaxed mb-4">
          אנחנו לא רוצים לפספס כלום,
          <br />
          בבקשה עקבו אחרינו ותייגו את האינסטגרם
          <br />
          של החתונה כדי שנוכל לחיות מחדש את הרגעים
        </p>
        <a
          href="https://instagram.com"
          target="_blank"
          rel="noopener noreferrer"
          className="inline-block px-6 py-2 bg-primary text-primary-foreground font-body text-xs tracking-[0.15em] uppercase rounded-sm hover:opacity-90 transition-opacity"
        >
          @OUR.WEDDING
        </a>

        {/* Playlist */}
        <div className="mt-14">
          <Music className="w-8 h-8 mx-auto text-wedding-warm mb-3" />
          <h2 className="font-heading text-2xl font-semibold text-foreground mb-3">פלייליסט</h2>
          <p className="font-body text-muted-foreground text-sm leading-relaxed">
            המסיבה שלכם,
            <br />
            עזרו לנו עם המוזיקה,
            <br />
            המליצו על השיר שחייב להתנגן
          </p>
        </div>
      </div>
    </section>
  );
};

export default InstagramSection;
