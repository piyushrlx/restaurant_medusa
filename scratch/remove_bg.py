from PIL import Image
import os

input_path = r'C:\Users\LENOVO\.gemini\antigravity-ide\brain\3cc9f541-e548-49b3-8144-6f5633e049d8\delivery_rider_no_road_1781730530990.png'
output_path = 'assets/images/delivery_rider_transparent.png'

img = Image.open(input_path).convert("RGBA")
datas = img.getdata()

newData = []
for item in datas:
    r, g, b, a = item
    # Green chroma-key logic: if green is significantly dominant
    if g > 120 and g > r + 30 and g > b + 30:
        newData.append((0, 0, 0, 0)) # Make transparent
    else:
        # Check if it's very close to green (e.g. edge anti-aliasing)
        # We can blend alpha based on green dominance
        if g > r and g > b:
            # Simple blending to remove green halo
            # Set alpha lower if green is highly dominant
            diff = min(g - r, g - b)
            if diff > 10:
                alpha = max(0, 255 - diff * 8)
                # Soften the green halo by replacing green component with average of r and b
                newData.append((r, int((r + b) / 2), b, int(alpha)))
            else:
                newData.append((r, g, b, a))
        else:
            newData.append((r, g, b, a))

img.putdata(newData)

# Let's crop the image to the bounding box of non-transparent pixels
bbox = img.getbbox()
if bbox:
    img = img.crop(bbox)
    print("Cropped image bounding box:", bbox)

# Save the resulting image
img.save(output_path, "PNG")
print(f"Saved transparent image to {output_path}")
