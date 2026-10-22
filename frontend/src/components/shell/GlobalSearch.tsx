'use client';

import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { useT } from '@/i18n';
import { MEMBER_SEARCH_WINDOW, searchMembers } from '@/lib/gaps';
import type { Member } from '@/lib/types';

export const SEARCH_INPUT_ID = 'global-member-search';

/** Find a member by number or name (Ctrl+K or /). Uses the G5 stand-in until the API can search. */
export default function GlobalSearch() {
  const t = useT();
  const router = useRouter();
  const [text, setText] = useState('');
  const [options, setOptions] = useState<Member[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (text.trim().length < 1) return;
    let live = true;
    const timer = setTimeout(() => {
      setLoading(true);
      searchMembers(text)
        .then((m) => live && setOptions(m))
        .catch(() => live && setOptions([]))
        .finally(() => live && setLoading(false));
    }, 200);
    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [text]);

  return (
    <Autocomplete
      id={SEARCH_INPUT_ID}
      options={text.trim() ? options : []}
      loading={loading}
      filterOptions={(o) => o}
      getOptionLabel={(m) => `${m.member_number ?? '—'} ${m.full_name}`}
      isOptionEqualToValue={(a, b) => a.id === b.id}
      noOptionsText={text.trim() ? t('shell.searchNoResults') : t('shell.searchPlaceholder')}
      onInputChange={(_, value) => setText(value)}
      onChange={(_, m) => {
        if (m) {
          router.push(`/members/${m.id}`);
          setText('');
        }
      }}
      value={null}
      blurOnSelect
      clearOnBlur
      sx={{ width: { xs: '100%', sm: 280, md: 360 } }}
      renderOption={({ key, ...props }, m) => (
        <Box component="li" key={key} {...props}>
          <Box>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>{m.full_name}{m.full_name_ne && ` · ${m.full_name_ne}`}</Typography>
            <Typography variant="caption" color="text.secondary">No. {m.member_number ?? '—'} · {m.status}</Typography>
          </Box>
        </Box>
      )}
      slotProps={{ listbox: { 'aria-describedby': `${SEARCH_INPUT_ID}-note` } as object }}
      renderInput={(params) => (
        <TextField
          {...params}
          placeholder={t('shell.searchPlaceholder')}
          helperText={text.trim() ? <span id={`${SEARCH_INPUT_ID}-note`}>{t('shell.searchLimited', { count: MEMBER_SEARCH_WINDOW })}</span> : undefined}
          slotProps={{
            ...params.slotProps,
            htmlInput: { ...params.slotProps.htmlInput, 'aria-label': t('shell.searchLabel') },
            input: { ...params.slotProps.input, startAdornment: <InputAdornment position="start"><SearchOutlined fontSize="small" /></InputAdornment> },
            formHelperText: { sx: { position: 'absolute', top: '100%', m: 0, mt: 0.25, whiteSpace: 'nowrap', bgcolor: 'background.paper', px: 0.5 } },
          }}
        />
      )}
    />
  );
}
