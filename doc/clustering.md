# Clustering

How `CreateClustersTask` groups the faces, why it works that way, and what it
gives up. For the tables it writes, see [data-model.md](data-model.md).

## The short version

Every run clusters **the faces that have no cluster yet, plus a few faces of
each existing cluster**, with the same chinese whispers the app has always used,
and reads the result through those sampled faces. A face that already has a
cluster is never moved, so a cluster keeps its id and nothing can take away the
name the user gave it.

Before 0.9.91 the whole partition was recomputed on every run, and any change
triggered it.

## Why not recompute

Chinese whispers is a stochastic label propagation: each step takes a node at
random and gives it the most common label among its neighbours. Changing the
number of nodes changes the whole random walk, so the partition is redrawn from
scratch. Measured on 3510 real faces in 862 clusters, threshold 0.4:

| change | faces whose cluster mates changed | faces with another label | person rows deleted |
| --- | --- | --- | --- |
| nothing, same input | 0 % | 0 % | 0 |
| **one image added** | **64 %** | 37 % | **18** |
| 5 images added | 70 % | 36 % | 14 |
| one image removed | 53 % | 95 % | 15 |

Every deleted person row was a name the user had to write again.

It is not noise in the descriptors, it is the shape of the graph. A face is
within 0.4 of only **22 %** of its own cluster on average, and 11.7 % of faces
hang from a single neighbour: the clusters are chains, and which end of a chain
propagates first depends on the visit order.

And there is nothing to gain by recomputing. Clustering the same faces again with
the input in another order agrees with itself at B³ F **0.889 to 0.908**, so that
band is the best any alternative can be asked to match.

## The algorithm

Per user, on every run:

1. Take up to `clustering_faces_per_run` faces with no cluster, oldest first.
2. Take up to `clustering_samples_per_cluster` faces of each existing cluster,
   the oldest of each, and only faces that could be grouped.
3. Run chinese whispers over the union, at the usual `sensitivity`.
4. Read each group through the sampled faces it contains:
   - **samples of one cluster** → the new faces of the group join it;
   - **no samples** → the new faces are a new cluster;
   - **samples of two or more clusters** → those clusters are the same
     appearance of one person, split by the order the faces arrived in, so the
     biggest absorbs the others.
5. Yield, and repeat while there are faces left.

Faces that cannot be grouped are handled before all of this: each one gets a
cluster of its own and is never compared with anything.

Deleting an image deletes its faces and nothing else. The clusters left without
faces are removed at the end of the next run, and so are the people left without
clusters.

The mapping of step 4 is `CreateClustersTask::planGroups()`, a static function
with no dependencies: every decision about the identity of a cluster is taken
there, and `tests/Unit/PlanGroupsTest.php` covers it without a database.

### What is never done automatically

- A cluster that belongs to a person is **not absorbed**. It can absorb the ones
  nobody assigned, which is how a person reaches the faces of a fragment.
- A cluster the user hid is not absorbed either, and does not absorb visible
  ones.
- On a tie of size, the oldest cluster survives, so the result does not depend
  on the order the groups came out in.

A merge cannot collapse two ages or two poses of one person, because those are
farther apart than the sensitivity and never land in the same group. What it
repairs is one appearance split in two by batching.

## Why sampling a few faces is enough

Chinese whispers never compares a face against a whole cluster: every decision
looks at the neighbours of that one node, and adopting the label of a single
neighbour is enough to join. So a sample only has to contain **one** neighbour of
the arriving face.

How likely that is follows from the density above:

| samples per cluster | chance of holding a neighbour | faces actually attached |
| --- | --- | --- |
| 1 | 21.7 % | 53.7 % |
| 3 | 37.8 % | 64.9 % |
| 10 | 61.3 % | **69.4 %** |
| 20 | 73.7 % | — |

Attached is higher than the chance because the arriving faces also reach a
cluster through each other. Those eight points are what chinese whispers adds
over a plain nearest neighbour test, which is why it is still used here.

Which faces are sampled barely matters: the oldest give 69.4 %, at random 65.9 %,
the biggest 66.2 %. So the oldest are taken, which is one `ORDER BY id LIMIT k`
and needs no extra state.

## What it costs, and how to bound it

The input is `clustering_faces_per_run + clusters * clustering_samples_per_cluster`
faces, and the work grows with the square of it. With the distance function of
pdlib at 0.63 µs per pair:

| input faces | pairs | time |
| --- | --- | --- |
| 5000 | 12.5 M | ~8 s |
| 10000 | 50 M | ~31 s |
| 20000 | 200 M | ~2 min |
| 40000 | 800 M | ~8 min |

Memory is dominated by the descriptors, 2.6 kB each once decoded, and by the
edge list, 233 B per pair below the threshold.

**A cluster with fifty thousand faces contributes as many faces as one with
five.** Nothing in a run scales with the size of a cluster, which is what makes
the cost predictable. The task yields after each batch, so a run that is out of
its `-t` timeout stops and the next one continues with the remaining faces.

## Growing from empty

The same loop builds a library from scratch, in batches. It converges to what
clustering everything at once would have given, and how closely depends on the
batch:

| batch | clusters | B³ F against one batch over everything |
| --- | --- | --- |
| 500 | 1015 | 0.874 |
| 1000 | 999 | 0.880 |
| 2000 | 943 | **0.907** |
| everything (3715) | 902 | 1.000 |

With a batch of 2000 out of 3715 faces it is already inside the 0.889–0.908 band
that the algorithm reaches against itself. So **the batch is not only a resource
knob, it is the convergence knob**: make it as big as the memory allows, above
all for the first pass over a library that was already analyzed.

The merges of step 4 are what makes this work. Without them the same run gives
0.759, with recall falling to 0.666: the fragments never find each other again.

Passes that only merge, over the samples and with no new faces, were measured and
dropped: they moved 0.874 to 0.877. A fragment hangs from faces that are not in
the samples, so comparing samples against samples does not find it.

## Suggesting who a cluster is

One person is several clusters, and no threshold fixes that. At 0.4 the clusters
are pure: over 3715 real faces, **not one cluster held two faces of a single
image**, which are necessarily two people. Raising the threshold to gain the
missing recall destroys that fast:

| threshold | impure clusters | faces in them |
| --- | --- | --- |
| 0.40 | 0 | 0 |
| 0.45 | 1 | 433 |
| 0.50 | 6 | 2173 |

So the clusters are left alone and the candidates are proposed instead:
`GET /cluster/{id}/similar` compares a few faces of the cluster against a few of
every other one and returns the closest, ranked. Pairs that share an image are
dropped, since those cannot be one person.

Ranked that way, on the same dataset:

| pairs shown | provably impossible | clusters they hold |
| --- | --- | --- |
| 10 | 0 % | 501 faces (13 %) |
| 50 | 0 % | 1747 faces (47 %) |
| 250 | 0.4 % | 2965 faces (**80 %**) |

Nothing is stored: the candidates are computed when asked for, out of `k` samples
per cluster, so there is no table to keep up to date when a cluster changes. The
scan goes in chunks of 250 clusters and keeps only the best, so the memory does
not depend on how many clusters there are.

Naming a candidate is what links it: the cluster is pointed at the person, and
one answer holds for all its faces.

## Settings

| setting | default | what it does |
| --- | --- | --- |
| `sensitivity` | 0.4 | Distance below which two faces are neighbours. |
| `min_confidence` | 0.99 | Faces below this are not grouped. |
| `min_face_size` | 40 | Faces smaller than this are not grouped. |
| `min_faces_in_cluster` | 5 | Clusters smaller than this are not shown. |
| `clustering_faces_per_run` | 5000 | Faces without a cluster taken per run. Also the convergence knob. |
| `clustering_samples_per_cluster` | 10 | Faces of each cluster put in with them. |
| `link_suggestion_sensitivity` | 0.55 | Distance up to which two clusters are proposed as one person. Looser than the sensitivity on purpose. |
| `link_suggestion_samples` | 3 | Faces of each cluster compared when looking for candidates. |

Changing `sensitivity` or `min_confidence` means what is in the database was
obtained with other parameters, so the clusters are discarded and grown again on
the next run. **That loses the names**, exactly as `occ face:reset --clustering`.

## What this gives up

The algorithm cannot undo its own mistakes. A face left in the wrong cluster
stays there, and two clusters that should be one are only put back together when
a face that belongs to both arrives. When that is not enough,
`occ face:reset --clustering` followed by a run with a big
`clustering_faces_per_run` rebuilds everything, which is what every run used to
do and is now an explicit decision.

## How to evaluate a change

Comparing against a full rebuild is not the metric. The rebuild does not agree
with itself, and it fragments one person into several clusters on purpose. What
to measure instead:

- **purity**: clusters holding two faces of one image, which must stay at zero;
- **churn**: faces that already had a cluster and changed, which must be zero
  except for the ones whose cluster was absorbed;
- **person rows deleted** when images are added, which must be zero;
- **clicks to cover X % of the faces** through the suggestions, which is what
  measures the work left to the user;
- **peak distances and memory per run**, which is what the two knobs bound.
