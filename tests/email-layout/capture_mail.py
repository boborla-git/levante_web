from pathlib import Path
import sys
root=Path(__file__).parent/'output'/'mail'
root.mkdir(parents=True,exist_ok=True)
(root/f'{len(list(root.glob("*.eml"))):03}.eml').write_bytes(sys.stdin.buffer.read())
