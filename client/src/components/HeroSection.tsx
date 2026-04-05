import coupleHero from "@/assets/couple-hero.jpg";

const HeroSection = () => {
  return (
    <section className="relative w-full">
      <div className="relative h-[70vh] overflow-hidden">
        <img
          src={coupleHero}
          alt="תמונת זוג"
          className="w-full h-full object-cover"
          width={800}
          height={1000}
        />
        <div className="absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-background/80" />
        <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
          <p className="font-body text-primary-foreground text-sm tracking-[0.3em] uppercase mb-2">אנחנו מתחתנים</p>
          <h1 className="font-script text-6xl md:text-8xl text-primary-foreground drop-shadow-lg">
            אביה & דודי
          </h1>
          <p className="font-body text-primary-foreground text-lg tracking-[0.2em] mt-4">25.05.2026</p>
        </div>
      </div>
    </section>
  );
};

export default HeroSection;
