import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";

const TYPING_SENTENCES = [
  "עד שנאתר את ההמלצות הכי מתאימות עבורך...",
  "מחפשים את המומחים הטובים ביותר באזור שלך...",
  "סורקים המלצות מחברים ומכרים...",
  "מנתחים דירוגים וחוות דעת עבורך...",
  "מתאימים תוצאות בדיוק למה שאתה צריך...",
];

export default function TypingIndicator() {
  const [sentenceIndex, setSentenceIndex] = useState(() =>
    Math.floor(Math.random() * TYPING_SENTENCES.length)
  );
  const [displayedText, setDisplayedText] = useState("");
  const [charIndex, setCharIndex] = useState(0);

  const currentSentence = TYPING_SENTENCES[sentenceIndex];

  useEffect(() => {
    if (charIndex < currentSentence.length) {
      const timeout = setTimeout(() => {
        setDisplayedText(currentSentence.slice(0, charIndex + 1));
        setCharIndex(charIndex + 1);
      }, 40);
      return () => clearTimeout(timeout);
    } else {
      // Wait then switch to next sentence
      const timeout = setTimeout(() => {
        const next = (sentenceIndex + 1) % TYPING_SENTENCES.length;
        setSentenceIndex(next);
        setCharIndex(0);
        setDisplayedText("");
      }, 2000);
      return () => clearTimeout(timeout);
    }
  }, [charIndex, currentSentence, sentenceIndex]);

  return (
    <AnimatePresence mode="wait">
      <motion.p
        key={sentenceIndex}
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        className="text-sm text-muted-foreground"
        dir="rtl"
      >
        {displayedText}
        <span className="inline-block w-[2px] h-4 bg-muted-foreground align-middle animate-pulse ml-0.5" />
      </motion.p>
    </AnimatePresence>
  );
}
