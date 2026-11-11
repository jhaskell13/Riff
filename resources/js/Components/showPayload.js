// The server accepts exactly { artist_id } or { name } per lineup entry;
// status and the form's bookkeeping keys stay client-side.
export const showPayload = (data) => ({
    ...data,
    lineup: data.lineup.map((entry) => (entry.artist_id
        ? { artist_id: entry.artist_id }
        : { name: entry.name })),
});
