import React, { useState} from "react";
import Button from "@mui/material/Button";
import Dialog from "@mui/material/Dialog";
import DialogActions from "@mui/material/DialogActions";
import DialogContent from "@mui/material/DialogContent";
import DialogTitle from "@mui/material/DialogTitle";
import { IconButton, TextField, Grid2 as Grid, } from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import { usePage } from "@inertiajs/react";
import axios from "axios";
import Swal from "sweetalert2";

import { useSales } from "@/Context/SalesContext";
import { name } from "dayjs/locale/en-gb";

export default function SaleTemplateDialog({open, setOpen}) {

    const { cartState, emptyCart } = useSales();
    const [formState, setFormState] = useState([]);

    const handleClose = () => {
        setOpen(false);
    };

    // Handle form input changes
    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setFormState((prevState) => ({
            ...prevState,
            [name]: value,
        }));
    };

    const handleSubmit = (event) => {
        event.preventDefault();
        
        // Handle form submission logic here
        axios.post("/sale-templates", {
            cart_items: cartState,
            name: formState.name,
            note: formState.note,
        })
            .then((response) => {
                Swal.fire({
                    title: "Success!",
                    text: response.data.message,
                    icon: "success",
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true,
                });

                handleClose();
                emptyCart();
            })
            .catch((error) => {
                console.log(error);
            });
        // Close the dialog
        // setOpen(false);
    };

    return (
        <React.Fragment>
            <Dialog
                fullWidth={true}
                maxWidth={"sm"}
                open={open}
                disableRestoreFocus={true}
                disableEnforceFocus
                onClose={handleClose}
                aria-labelledby="alert-dialog-title"
                component={"form"}
                onSubmit={handleSubmit}
                aria-describedby="dialog-description"
            >
                <DialogTitle id="alert-dialog-title">
                    SAVE YOUR GROUP TEMPLATE
                </DialogTitle>
                <IconButton
                    aria-label="close"
                    onClick={handleClose}
                    sx={(theme) => ({
                        position: "absolute",
                        right: 8,
                        top: 8,
                        color: theme.palette.grey[500],
                    })}
                >
                    <CloseIcon />
                </IconButton>
                <DialogContent>
                    <Grid container spacing={2}>
                        <Grid size={12}>
                            <TextField
                                fullWidth
                                name="name"
                                label="Name"
                                variant="outlined"
                                value={formState.name}
                                onChange={handleInputChange}
                                sx={{
                                    mt: "0.5rem",
                                }}
                                required
                                onFocus={(event) => {
                                    event.target.select();
                                }}
                                slotProps={{
                                    inputLabel: {
                                        shrink: true,
                                    },
                                }}
                            />
                        </Grid>
                        <Grid size={12}>
                            <TextField
                                fullWidth
                                name="note"
                                label="Note"
                                variant="outlined"
                                value={formState.note}
                                onChange={handleInputChange}
                                sx={{
                                    mt: "0.5rem",
                                }}
                                onFocus={(event) => {
                                    event.target.select();
                                }}
                                slotProps={{
                                    inputLabel: {
                                        shrink: true,
                                    },
                                }}
                            />
                            </Grid>
                    </Grid>
                </DialogContent>
                <DialogActions>
                    <Grid container spacing={1} size={12} justifyContent={'center'} width={'100%'}>
                        <Grid size={{ xs: 12, sm: 12 }}>
                            <Button
                                variant="contained"
                                fullWidth
                                sx={{ paddingY: "10px", fontSize: "1.2rem" }}
                                type="submit"
                                color={'primary'}
                            >
                                SAVE
                            </Button>
                        </Grid>
                    </Grid>
                </DialogActions>
            </Dialog>
        </React.Fragment>
    );
}
