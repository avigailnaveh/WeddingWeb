const PHP_SERVER = (import.meta as any)?.env?.VITE_PHP_SERVER || "http://localhost/myyiiapp/web";

export async function utf8JsonFetch<T>(endpoint: string, payload: any): Promise<T> {
  const response = await fetch(endpoint, {
    method: "POST",
    headers: {
      "Content-Type": "application/json; charset=utf-8",
    },
    body: JSON.stringify(payload),
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`Request failed: ${response.statusText} - ${errorText}`);
  }

  const data = await response.json();
  return data as T;
}