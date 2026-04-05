import React from "react";
import { Link } from "react-router-dom";
import { motion } from "framer-motion";
import { Sparkles, Inbox, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";

export function AppShell({
  title,
  subtitle,
  children,
  right,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  right?: React.ReactNode;
}) {
  return (
    <div className="min-h-screen bg-background">
      <div className="mx-auto max-w-6xl p-4 md:p-8">
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.35 }}
          className="mb-6 flex flex-col gap-4"
        >
          <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <div>
              <div className="flex items-center gap-2">
                <Sparkles className="h-5 w-5" />
                <h1 className="text-2xl font-semibold">{title}</h1>
              </div>
              {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
            </div>
            {right}
          </div>

          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline" className="rounded-xl">
              <Link to="/submit" className="gap-2 inline-flex items-center">
                <Inbox className="h-4 w-4" />
                הכנסת המלצה
              </Link>
            </Button>
            <Button asChild variant="outline" className="rounded-xl">
              <Link to="/crm" className="gap-2 inline-flex items-center">
                <ShieldCheck className="h-4 w-4" />
                CRM (אישור/דחייה)
              </Link>
            </Button>
            <Button asChild variant="outline" className="rounded-xl">
              <Link to="/chat" className="gap-2 inline-flex items-center">
                <Inbox className="h-4 w-4" />
                צ'אט
              </Link>
            </Button>
          </div>
        </motion.div>

        {children}
      </div>
    </div>
  );
}
