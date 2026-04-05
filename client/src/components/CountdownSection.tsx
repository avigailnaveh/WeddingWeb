import { useState, useEffect } from "react";

const WEDDING_DATE = new Date(2026, 4, 25, 19, 0, 0);

const CountdownSection = () => {
  const [timeLeft, setTimeLeft] = useState(getTimeLeft());

  function getTimeLeft() {
    const now = new Date();
    const diff = WEDDING_DATE.getTime() - now.getTime();
    if (diff <= 0) return { days: 0, hours: 0, minutes: 0, seconds: 0 };
    return {
      days: Math.floor(diff / (1000 * 60 * 60 * 24)),
      hours: Math.floor((diff / (1000 * 60 * 60)) % 24),
      minutes: Math.floor((diff / (1000 * 60)) % 60),
      seconds: Math.floor((diff / 1000) % 60),
    };
  }

  useEffect(() => {
    const timer = setInterval(() => setTimeLeft(getTimeLeft()), 1000);
    return () => clearInterval(timer);
  }, []);

  const units = [
    { value: timeLeft.seconds, label: "שניות" },
    { value: timeLeft.minutes, label: "דקות" },
    { value: timeLeft.hours, label: "שעות" },
    { value: timeLeft.days, label: "ימים" },
  ];

  return (
    <section className="py-12 text-center bg-background">
      <p className="font-heading text-lg tracking-[0.2em] text-foreground mb-6">נשאר</p>
      <div className="flex justify-center gap-6 md:gap-10">
        {units.map((u) => (
          <div key={u.label} className="flex flex-col items-center">
            <span className="font-heading text-4xl md:text-5xl text-primary font-semibold">
              {String(u.value).padStart(u.label === "ימים" ? 1 : 2, "0")}
            </span>
            <span className="font-body text-xs text-muted-foreground mt-1 tracking-wider">{u.label}</span>
          </div>
        ))}
      </div>
      <p className="font-body text-muted-foreground mt-8 text-sm">
        מחכים לכם לחגוג איתנו
      </p>
      <div className="flex items-center justify-center gap-3 mt-4 font-heading text-foreground">
        <span className="border-b border-primary px-3 pb-1 text-sm">שני</span>
        <span className="text-3xl font-semibold text-primary">25</span>
        <span className="text-sm tracking-wider">מאי</span>
      </div>
    </section>
  );
};

export default CountdownSection;
